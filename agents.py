"""
╔══════════════════════════════════════════════════════════════╗
║           JOB SEARCH AUTOMATION AGENT  v2.0                  ║
║  Scrapes JobStreet, Indeed & LinkedIn for matching roles      ║
║  Compares against masterProfile.md tech-stack keywords        ║
║  Generates AI-powered cover letters via Google Gemini         ║
║  Sends beautifully formatted Telegram alerts on 85%+ matches  ║
║  Scheduled: Weekdays at 9:00 AM                               ║
╚══════════════════════════════════════════════════════════════╝
"""

import asyncio
import os
import re
import sys
import logging
import httpx
import schedule
import time
from datetime import datetime
from dataclasses import dataclass, field
from pathlib import Path
from dotenv import load_dotenv
from playwright.async_api import async_playwright, TimeoutError as PlaywrightTimeout

# ── Windows asyncio: Playwright requires ProactorEventLoop ────
# We suppress the noisy "Event loop is closed" cleanup errors
# that Windows ProactorEventLoop emits after Playwright finishes.
if sys.platform == "win32":
    import warnings
    warnings.filterwarnings("ignore", message=".*Event loop is closed.*")

    # Patch: silence ResourceWarning from proactor transport cleanup
    _original_del = None
    try:
        from asyncio.proactor_events import _ProactorBasePipeTransport
        _original_del = _ProactorBasePipeTransport.__del__

        def _silence_del(self):
            try:
                _original_del(self)
            except RuntimeError:
                pass

        _ProactorBasePipeTransport.__del__ = _silence_del
    except Exception:
        pass

# ── Bootstrap ─────────────────────────────────────────────────
load_dotenv()
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s │ %(levelname)-8s │ %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("JobAgent")

# ── Config ────────────────────────────────────────────────────
TELEGRAM_BOT_TOKEN: str  = os.getenv("TELEGRAM_BOT_TOKEN", "")
TELEGRAM_CHAT_ID: str    = os.getenv("TELEGRAM_CHAT_ID", "")
GEMINI_API_KEY: str       = os.getenv("GEMINI_API_KEY", "")
MATCH_THRESHOLD: int      = int(os.getenv("MATCH_THRESHOLD", "85"))
JOBS_PER_QUERY: int       = int(os.getenv("JOBS_PER_QUERY", "10"))
PROFILE_PATH: str         = os.getenv("PROFILE_PATH", "masterProfile.md")
JOB_LOCATION: str         = os.getenv("JOB_LOCATION", "Malaysia")
ENABLED_SCRAPERS: list    = [s.strip() for s in os.getenv("ENABLED_SCRAPERS", "jobstreet,indeed,linkedin").split(",")]
JOB_TITLES: list          = [t.strip() for t in os.getenv("JOB_TITLES", "Full-Stack Developer,Software Engineer,Data Analyst").split(",")]
COVER_LETTERS_DIR: Path   = Path("cover_letters")
DASHBOARD_URL: str        = os.getenv("DASHBOARD_URL", "")
DASHBOARD_API_KEY: str    = os.getenv("DASHBOARD_API_KEY", "")



# ── Data Models ───────────────────────────────────────────────
@dataclass
class JobListing:
    title: str
    company: str
    location: str
    url: str
    description: str
    source: str
    posted_date: str = ""
    salary: str = ""


@dataclass
class MatchResult:
    job: JobListing
    score: float
    matched_primary: list[str] = field(default_factory=list)
    matched_secondary: list[str] = field(default_factory=list)
    matched_context: list[str] = field(default_factory=list)


# ══════════════════════════════════════════════════════════════
#  MODULE 1 — PROFILE PARSER
#  Reads masterProfile.md and extracts weighted keyword sets
# ══════════════════════════════════════════════════════════════
class ProfileParser:
    """Parses masterProfile.md to extract tech-stack keywords and profile text."""

    # ── PRIMARY: Syafiq's strongest, most marketable skills (weight × 2) ──
    PRIMARY_KEYWORDS = [
        # Core stack
        "Laravel", "PHP", "JavaScript", "SQL", "MySQL",
        # API & architecture
        "RESTful API", "REST API", "RESTful", "API Development", "MVC",
        # Tooling
        "Git", "Docker", "GitHub",
        # Role descriptors that directly match his targets
        "Full-Stack", "Full Stack", "Back-End", "Backend", "Web Developer",
        # DB
        "Relational Database", "Database Design", "Eloquent ORM",
        # Deployment
        "Render", "Cloud Deployment",
    ]

    # ── SECONDARY: Familiar / learning — still relevant (weight × 1) ──
    SECONDARY_KEYWORDS = [
        # Front-end
        "HTML5", "HTML", "CSS3", "CSS", "Bootstrap", "jQuery",
        # Expanding stack
        "Python", "TypeScript",
        "React", "Node.js", "Node", "Express", "MERN Stack", "MERN",
        # DB extras
        "MongoDB", "SQLite", "NoSQL",
        # Infra / tools
        "Deployment", "Cloud", "cPanel", "Postman", "Automation",
        # Platforms
        "WordPress",
    ]

    # ── CONTEXT: Role & domain keywords that improve relevance signal ──
    CONTEXT_KEYWORDS = [
        # ★ Entry-level role titles (primary targets)
        "Junior Software Developer", "Junior Web Developer", "Junior PHP Developer",
        "Junior Laravel Developer", "Junior Data Analyst", "Junior Developer",
        "Junior Software Engineer", "Graduate Developer", "Associate Developer",
        "IT Executive", "IT Graduate", "System Developer", "Application Developer",
        "Trainee Developer", "Trainee",
        # General role signals
        "Developer", "Programmer", "Engineer",
        # Seniority signals in JD text
        "Entry Level", "Fresh Graduate", "Junior", "Graduate", "Entry-Level",
        "0-2 years", "0 to 2 years", "no experience required",
        # Domain / methodology
        "Big Data", "CRISP-DM", "Business Intelligence", "Data Mining",
        "Analytics", "Reporting", "Dashboard",
        "Agile", "SDLC", "OOP", "Object-Oriented",
        # Industry context
        "API", "CRUD", "HRMS", "ERP", "POS", "SaaS",
        "Internal Tools", "Management System", "Web Application",
        # Soft skill signals common in JDs
        "Problem Solving", "Fast Learner", "Team Player",
    ]

    def __init__(self, profile_path: str = PROFILE_PATH):
        self.profile_path = Path(profile_path)
        self.raw_text = ""
        self._load()

    def _load(self):
        if not self.profile_path.exists():
            log.warning(f"Profile not found at {self.profile_path}. Using default keywords.")
            return
        self.raw_text = self.profile_path.read_text(encoding="utf-8")
        log.info(f"✅ Profile loaded: {self.profile_path} ({len(self.raw_text)} bytes)")

    @property
    def all_keywords(self) -> dict:
        return {
            "primary": self.PRIMARY_KEYWORDS,
            "secondary": self.SECONDARY_KEYWORDS,
            "context": self.CONTEXT_KEYWORDS,
        }


# ══════════════════════════════════════════════════════════════
#  MODULE 2 — MATCH ENGINE
#  Scores a job description against the profile keywords
# ══════════════════════════════════════════════════════════════
class MatchEngine:
    """Scores a JobListing against the user's skill profile using track-based target scoring."""

    def __init__(self, profile: ProfileParser):
        self.profile = profile

    def score(self, job: JobListing) -> MatchResult:
        text = f"{job.title} {job.description}".lower()

        def find_matches(keywords: list[str]) -> list[str]:
            return [kw for kw in keywords if kw.lower() in text]

        matched_primary   = find_matches(self.profile.PRIMARY_KEYWORDS)
        matched_secondary = find_matches(self.profile.SECONDARY_KEYWORDS)
        matched_context   = find_matches(self.profile.CONTEXT_KEYWORDS)

        # ── TRACK-BASED MATCH SCORING ──
        # Track 1: Junior Web / PHP / Full-Stack Developer
        web_primary = ["laravel", "php", "javascript", "codeigniter", "react", "wordpress"]
        web_secondary = ["mysql", "sql", "html", "css", "bootstrap", "jquery", "git", "docker", "render", "cpanel", "rest"]
        
        # Track 2: Junior Data Analyst
        data_primary = ["power bi", "powerbi", "crisp-dm", "data mining", "data visualization"]
        data_secondary = ["mysql", "sql", "python", "big data", "reporting", "dashboard", "excel", "analytics"]
        
        # Track 3: IT Executive / General Tech
        it_primary = ["it executive", "technical support", "system developer", "application developer"]
        it_secondary = ["database", "network", "cpanel", "infrastructure", "hardware", "software", "support"]

        web_matches_p = [k for k in web_primary if k in text]
        web_matches_s = [k for k in web_secondary if k in text]
        
        data_matches_p = [k for k in data_primary if k in text]
        data_matches_s = [k for k in data_secondary if k in text]
        
        it_matches_p = [k for k in it_primary if k in text]
        it_matches_s = [k for k in it_secondary if k in text]

        # Calculate scores out of 100 per track (matching 3 primary OR 2 primary + 2 secondary is a strong match)
        web_score = (len(web_matches_p) * 25) + (len(web_matches_s) * 15)
        data_score = (len(data_matches_p) * 25) + (len(data_matches_s) * 15)
        it_score = (len(it_matches_p) * 30) + (len(it_matches_s) * 15)

        best_track_score = max(web_score, data_score, it_score)
        best_track_score = min(best_track_score, 100.0)

        # ── SENIORITY & CONTEXT PENALTY/BONUS ──
        senior_terms = ["senior", "lead", "manager", "director", "head", "principal", "sr.", "5+ years", "8+ years", "10+ years"]
        junior_terms = ["junior", "fresh grad", "entry level", "trainee", "associate", "graduate", "0-2 years", "0 to 2 years", "no experience"]

        title_lower = job.title.lower()
        title_senior = any(t in title_lower for t in ["senior", "lead", "manager", "director", "head", "principal", "sr."])
        
        has_senior = title_senior or any(t in text for t in senior_terms)
        has_junior = any(t in text or t in title_lower for t in junior_terms)

        score = best_track_score
        
        # Heavy penalty if title is senior OR description is senior without junior references
        if title_senior:
            score -= 40  # Always penalize senior titles
        elif has_senior and not has_junior:
            score -= 30
        # Bonus if role is explicitly junior/entry-level
        elif has_junior and not title_senior:
            score += 15


        # Small bonus for general context match count
        score += min(len(matched_context) * 2, 10.0)

        score = max(0.0, min(100.0, score))

        return MatchResult(
            job=job,
            score=round(score, 1),
            matched_primary=matched_primary,
            matched_secondary=matched_secondary,
            matched_context=matched_context,
        )



# ══════════════════════════════════════════════════════════════
#  MODULE 3 — SCRAPERS
#  Resilient multi-selector scrapers for each job board
# ══════════════════════════════════════════════════════════════
class JobScraper:
    """Base class for all job scrapers."""

    SOURCE_NAME = "Unknown"

    def __init__(self, browser):
        self.browser = browser

    async def _new_page(self):
        ctx = await self.browser.new_context(
            user_agent=(
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/125.0.0.0 Safari/537.36"
            ),
            locale="en-MY",
            viewport={"width": 1280, "height": 800},
        )
        page = await ctx.new_page()
        return page

    async def scrape(self, job_title: str, location: str) -> list[JobListing]:
        raise NotImplementedError

    async def _safe_text(self, element) -> str:
        """Safely get inner text from an element."""
        try:
            return (await element.inner_text()).strip() if element else ""
        except Exception:
            return ""

    async def _safe_attr(self, element, attr: str) -> str:
        """Safely get attribute from an element."""
        try:
            return (await element.get_attribute(attr) or "").strip() if element else ""
        except Exception:
            return ""

    async def _fetch_description(self, page, url: str) -> str:
        """Safely fetches full description text from detail pages."""
        if not url:
            return ""
        detail_page = None
        try:
            detail_page = await page.context.new_page()
            await detail_page.goto(url, timeout=15000, wait_until="domcontentloaded")
            await detail_page.wait_for_timeout(1000)

            desc = ""
            if "linkedin.com" in url:
                desc = (
                    await self._safe_text(await detail_page.query_selector(".show-more-less-html__markup")) or
                    await self._safe_text(await detail_page.query_selector(".description__text")) or
                    await self._safe_text(await detail_page.query_selector("section.description")) or
                    await self._safe_text(await detail_page.query_selector(".jobs-description"))
                )
            elif "jobstreet" in url:
                desc = (
                    await self._safe_text(await detail_page.query_selector("[data-automation='jobDescription']")) or
                    await self._safe_text(await detail_page.query_selector("[class*='jobDescription']"))
                )
            elif "indeed.com" in url:
                desc = (
                    await self._safe_text(await detail_page.query_selector("#jobDescriptionText")) or
                    await self._safe_text(await detail_page.query_selector(".jobsearch-jobDescriptionText"))
                )

            await detail_page.close()
            return desc
        except Exception as e:
            log.debug(f"Failed to fetch description from {url}: {e}")
            if detail_page:
                try:
                    await detail_page.close()
                except Exception:
                    pass
            return ""


# ── JobStreet Scraper ─────────────────────────────────────────
class JobStreetScraper(JobScraper):
    SOURCE_NAME = "JobStreet"

    # Confirmed working selectors (JobStreet uses Seek platform with data-automation)
    CARD_SELECTORS = [
        "article[data-automation='normalJob'], article[data-automation='premiumJob']",
        "article[data-automation='normalJob']",
        "article[data-automation='premiumJob']",
        "article[data-card-type='JobCard']",
        "[data-automation='jobCard']",
        "article",  # broad fallback — warning: may include non-job articles
    ]

    async def scrape(self, job_title: str, location: str) -> list[JobListing]:
        jobs: list[JobListing] = []
        query = re.sub(r'\s+', '-', job_title.lower())
        loc   = location.lower()
        url   = f"https://www.jobstreet.com.my/jobs/{query}-jobs-in-{loc}?createdAt=1d"

        page = await self._new_page()
        try:
            log.info(f"[JobStreet] Scraping: {job_title}")
            await page.goto(url, timeout=30000, wait_until="domcontentloaded")
            await page.wait_for_timeout(3000)

            # Try each selector until we find cards
            cards = []
            for selector in self.CARD_SELECTORS:
                cards = await page.query_selector_all(selector)
                if cards:
                    log.info(f"[JobStreet] Found {len(cards)} cards using selector: {selector}")
                    break

            if not cards:
                log.warning(f"[JobStreet] No cards found for '{job_title}' — page may have changed")
                # Debug: log page title
                title = await page.title()
                log.debug(f"[JobStreet] Page title: {title}")

            for card in cards[:JOBS_PER_QUERY]:
                try:
                    job = await self._parse_card(page, card, job_title, location)
                    if job:
                        jobs.append(job)
                except Exception as e:
                    log.debug(f"[JobStreet] Card parse error: {e}")

        except PlaywrightTimeout:
            log.warning(f"[JobStreet] Timeout scraping {job_title}")
        except Exception as e:
            log.error(f"[JobStreet] Error: {e}")
        finally:
            try:
                await page.context.close()
            except Exception:
                pass

        return jobs

    async def _parse_card(self, page, card, default_title: str, default_loc: str):
        # Use confirmed data-automation attributes (Seek/JobStreet platform)
        title = (
            await self._safe_text(await card.query_selector("[data-automation='jobTitle']")) or
            await self._safe_text(await card.query_selector("h1")) or
            await self._safe_text(await card.query_selector("h2")) or
            await self._safe_text(await card.query_selector("h3"))
        )
        company = (
            await self._safe_text(await card.query_selector("[data-automation='jobCompany']")) or
            await self._safe_text(await card.query_selector("[data-automation='jobListingCompany']")) or
            "Unknown Company"
        )
        location = (
            await self._safe_text(await card.query_selector("[data-automation='jobLocation']")) or
            await self._safe_text(await card.query_selector("[data-automation='jobListingLocation']")) or
            default_loc
        )
        salary = (
            await self._safe_text(await card.query_selector("[data-automation='jobSalary']")) or
            await self._safe_text(await card.query_selector("[data-automation='jobListingSalary']")) or
            ""
        )
        link_el = (
            await card.query_selector("a[data-automation='jobTitle']") or
            await card.query_selector("a[href*='/job-detail/']") or
            await card.query_selector("a[href*='jobstreet']")
        )
        href = await self._safe_attr(link_el, "href")
        full_url = f"https://www.jobstreet.com.my{href}" if href.startswith("/") else href

        # Fetch full description asynchronously
        desc = await self._fetch_description(page, full_url)
        if not desc:
            desc = (
                await self._safe_text(await card.query_selector("[data-automation='jobShortDescription']")) or
                await self._safe_text(await card.query_selector("[data-automation='jobDescription']")) or
                ""
            )

        # Skip cards with no meaningful title (nav/header articles)
        if not title or len(title.strip()) < 3:
            return None

        return JobListing(
            title=title.strip(), company=company.strip(), location=location.strip(),
            url=full_url, description=desc[:3000],
            source=self.SOURCE_NAME, salary=salary.strip(),
        )



# ── Indeed Scraper ────────────────────────────────────────────
class IndeedScraper(JobScraper):
    SOURCE_NAME = "Indeed"

    # Indeed Malaysia (my.indeed.com) — updated selectors for current DOM
    CARD_SELECTORS = [
        ".job_seen_beacon",
        "[data-testid='slider_item']",
        "div[class*='job_seen']",
        "#mosaic-provider-jobcards li",
        ".jobsearch-ResultsList li",
        "li[class*='result']",
        "td.resultContent",
    ]

    async def scrape(self, job_title: str, location: str) -> list[JobListing]:
        jobs: list[JobListing] = []
        query = re.sub(r'\s+', '+', job_title)
        # Use sg.indeed.com which often works better for SEA region
        url   = f"https://sg.indeed.com/jobs?q={query}&l={location}&fromage=1&sort=date&radius=100"

        page = await self._new_page()
        try:
            log.info(f"[Indeed] Scraping: {job_title}")
            await page.goto(url, timeout=30000, wait_until="domcontentloaded")
            await page.wait_for_timeout(3000)

            # Dismiss cookie consent if present
            try:
                consent_btn = await page.query_selector("button[id*='accept']")
                if consent_btn:
                    await consent_btn.click()
                    await page.wait_for_timeout(1000)
            except Exception:
                pass

            cards = []
            for selector in self.CARD_SELECTORS:
                cards = await page.query_selector_all(selector)
                if cards:
                    log.info(f"[Indeed] Found {len(cards)} cards using selector: {selector}")
                    break

            if not cards:
                log.warning(f"[Indeed] No cards found for '{job_title}'")

            for card in cards[:JOBS_PER_QUERY]:
                try:
                    job = await self._parse_card(page, card, job_title, location)
                    if job:
                        jobs.append(job)
                except Exception as e:
                    log.debug(f"[Indeed] Card parse error: {e}")

        except PlaywrightTimeout:
            log.warning(f"[Indeed] Timeout scraping {job_title}")
        except Exception as e:
            log.error(f"[Indeed] Error: {e}")
        finally:
            try:
                await page.context.close()
            except Exception:
                pass

        return jobs

    async def _parse_card(self, page, card, default_title: str, default_loc: str):
        title = (
            await self._safe_text(await card.query_selector("[data-testid='jobTitle'] span")) or
            await self._safe_text(await card.query_selector("[data-testid='jobTitle']")) or
            await self._safe_text(await card.query_selector("h2 a span")) or
            await self._safe_text(await card.query_selector("h2 span")) or
            await self._safe_text(await card.query_selector(".jobTitle span"))
        )
        company = (
            await self._safe_text(await card.query_selector("[data-testid='company-name']")) or
            await self._safe_text(await card.query_selector(".companyName")) or
            await self._safe_text(await card.query_selector("[class*='company']")) or
            "Unknown Company"
        )
        location = (
            await self._safe_text(await card.query_selector("[data-testid='text-location']")) or
            await self._safe_text(await card.query_selector(".companyLocation")) or
            await self._safe_text(await card.query_selector(".locationsContainer")) or
            default_loc
        )
        salary = (
            await self._safe_text(await card.query_selector(".salary-snippet-container")) or
            await self._safe_text(await card.query_selector(".salaryOnly")) or
            await self._safe_text(await card.query_selector("[data-testid='attribute_snippet_testid']")) or
            ""
        )
        link_el = (
            await card.query_selector("a[data-jk]") or
            await card.query_selector("h2 a") or
            await card.query_selector("a[href*='viewjob']") or
            await card.query_selector("a[href*='clk']")  
        )
        jk   = await self._safe_attr(link_el, "data-jk")
        href = await self._safe_attr(link_el, "href")
        full_url = f"https://sg.indeed.com/viewjob?jk={jk}" if jk else (
            f"https://sg.indeed.com{href}" if href.startswith("/") else href
        )
        
        # Fetch full description asynchronously
        desc = await self._fetch_description(page, full_url)
        if not desc:
            desc = (
                await self._safe_text(await card.query_selector(".job-snippet")) or
                await self._safe_text(await card.query_selector("[data-testid='jobsnippet_container']")) or
                await self._safe_text(await card.query_selector(".underShelfFooter")) or
                ""
            )

        if not title or len(title.strip()) < 2:
            return None

        return JobListing(
            title=title.strip(), company=company.strip(), location=location.strip(),
            url=full_url, description=desc[:3000],
            source=self.SOURCE_NAME, salary=salary.strip(),
        )



# ── LinkedIn Scraper ──────────────────────────────────────────
class LinkedInScraper(JobScraper):
    SOURCE_NAME = "LinkedIn"

    CARD_SELECTORS = [
        "ul.jobs-search__results-list li",
        ".base-search-card",
        "li[class*='jobs-search']",
        "div[class*='job-search-card']",
        ".job-search-card",
    ]

    async def scrape(self, job_title: str, location: str) -> list[JobListing]:
        jobs: list[JobListing] = []
        query = re.sub(r'\s+', '%20', job_title)
        url   = (
            f"https://www.linkedin.com/jobs/search/"
            f"?keywords={query}&location={location}"
            f"&f_TPR=r86400&sortBy=DD"
        )

        page = await self._new_page()
        try:
            log.info(f"[LinkedIn] Scraping: {job_title}")
            await page.goto(url, timeout=30000, wait_until="domcontentloaded")
            await page.wait_for_timeout(3500)

            # Dismiss sign-in modal if present
            try:
                dismiss = await page.query_selector("button[data-tracking-control-name='guest_homepage-basic_join-link-close']")
                if dismiss:
                    await dismiss.click()
                    await page.wait_for_timeout(500)
            except Exception:
                pass

            cards = []
            for selector in self.CARD_SELECTORS:
                cards = await page.query_selector_all(selector)
                if cards:
                    log.info(f"[LinkedIn] Found {len(cards)} cards using selector: {selector}")
                    break

            if not cards:
                log.warning(f"[LinkedIn] No cards found for '{job_title}'")

            for card in cards[:JOBS_PER_QUERY]:
                try:
                    job = await self._parse_card(page, card, job_title, location)
                    if job:
                        jobs.append(job)
                except Exception as e:
                    log.debug(f"[LinkedIn] Card parse error: {e}")

        except PlaywrightTimeout:
            log.warning(f"[LinkedIn] Timeout scraping {job_title}")
        except Exception as e:
            log.error(f"[LinkedIn] Error: {e}")
        finally:
            try:
                await page.context.close()
            except Exception:
                pass

        return jobs

    async def _parse_card(self, page, card, default_title: str, default_loc: str):
        title = (
            await self._safe_text(await card.query_selector(".base-search-card__title")) or
            await self._safe_text(await card.query_selector("h3")) or
            default_title
        )
        company = (
            await self._safe_text(await card.query_selector(".base-search-card__subtitle")) or
            await self._safe_text(await card.query_selector("h4")) or
            "Unknown Company"
        )
        location = (
            await self._safe_text(await card.query_selector(".job-search-card__location")) or
            await self._safe_text(await card.query_selector("[class*='location']")) or
            default_loc
        )
        link_el = (
            await card.query_selector("a.base-card__full-link") or
            await card.query_selector("a[href*='/jobs/']") or
            await card.query_selector("a")
        )
        href = await self._safe_attr(link_el, "href")
        # Strip tracking params from LinkedIn URL
        href = href.split("?")[0] if href else ""

        time_el = await card.query_selector("time")
        posted  = await self._safe_attr(time_el, "datetime")

        # Fetch full description asynchronously
        desc = await self._fetch_description(page, href)
        if not desc:
            desc = (
                await self._safe_text(await card.query_selector(".base-search-card__metadata")) or
                ""
            )

        if not title:
            return None

        return JobListing(
            title=title.strip(), company=company.strip(), location=location.strip(),
            url=href, description=desc[:3000],
            source=self.SOURCE_NAME, posted_date=posted,
        )



# ══════════════════════════════════════════════════════════════
#  MODULE 4 — COVER LETTER GENERATOR
#  Uses Google Gemini API (free tier) to write tailored letters
#  Falls back to a rich template if no API key is set
# ══════════════════════════════════════════════════════════════
class CoverLetterGenerator:
    """Generates a personalised cover letter for each matched job."""

    GEMINI_URL = (
        "https://generativelanguage.googleapis.com/v1/models/"
        "gemini-1.5-flash:generateContent"
    )


    def __init__(self, profile: ProfileParser):
        self.profile = profile
        COVER_LETTERS_DIR.mkdir(exist_ok=True)

    def _build_prompt(self, result: MatchResult) -> str:
        job = result.job
        matched = result.matched_primary + result.matched_secondary
        return f"""
You are a professional career consultant helping a fresh graduate write a concise, compelling cover letter.

CANDIDATE PROFILE:
- Name: Muhammad Syafiq Norhazwan Bin Nor Ramzi
- Degree: Bachelor of IT (Hons.), Big Data, UiTM Arau — CGPA 3.51
- Target Roles: Full-Stack Developer, Software Engineer, Data Analyst
- Primary Skills: Laravel, PHP, JavaScript, MySQL, RESTful APIs, Git, Docker
- Key Projects:
  * ClockWise (HRMS): Solo full-stack Laravel app deployed on Render — automated payroll & attendance
  * JomOrder (POS): Laravel F&B ordering system with AI-augmented development
  * Internship at Goolee Sdn Bhd: Built Trainer Development Management System (WordPress + PHP)
- Notice Period: Immediate

JOB DETAILS:
- Role: {job.title}
- Company: {job.company}
- Location: {job.location}
- Matched Skills: {', '.join(matched[:8])}
- Job Description Snippet: {job.description[:800]}

INSTRUCTIONS:
Write a professional, warm, and confident cover letter (max 300 words). Structure:
1. Opening: Hook with genuine interest in {job.company} and the {job.title} role
2. Body: Highlight 2 most relevant projects/skills that directly match their needs
3. Closing: Express eagerness to contribute, invite them to reach out

Format as plain text with proper paragraphs. Do NOT use placeholders like [Your Name].
Sign off as: Muhammad Syafiq Norhazwan
""".strip()

    async def _call_gemini(self, prompt: str) -> str:
        """Calls Google Gemini API to generate the cover letter."""
        payload = {
            "contents": [{"parts": [{"text": prompt}]}],
            "generationConfig": {"maxOutputTokens": 600, "temperature": 0.75},
        }
        try:
            async with httpx.AsyncClient(timeout=30) as client:
                resp = await client.post(
                    f"{self.GEMINI_URL}?key={GEMINI_API_KEY}",
                    json=payload,
                )
                if resp.status_code == 200:
                    data = resp.json()
                    return data["candidates"][0]["content"]["parts"][0]["text"]
                else:
                    log.warning(f"[Gemini] API error {resp.status_code}: {resp.text[:200]}")
                    return ""
        except Exception as e:
            log.error(f"[Gemini] Request failed: {e}")
            return ""

    def _template_letter(self, result: MatchResult) -> str:
        """Rich template fallback when no Gemini API key is configured."""
        job = result.job
        matched = result.matched_primary + result.matched_secondary
        skills_str = ", ".join(matched[:6]) if matched else "full-stack development and databases"
        today = datetime.now().strftime("%d %B %Y")

        return f"""{today}

Hiring Manager
{job.company}
{job.location}

Dear Hiring Manager,

I am writing to express my keen interest in the {job.title} position at {job.company}. As a fresh IT graduate from UiTM Arau with a CGPA of 3.51 specialising in Big Data, I bring hands-on production experience in {skills_str} — skills that align directly with what your team needs.

During my time as a solo developer on ClockWise, an end-to-end Human Resource Management System built on the Laravel ecosystem, I engineered a complete payroll automation platform and successfully deployed it to a live Render cloud environment. This project sharpened my ability to architect clean RESTful APIs, design normalised relational databases in MySQL, and manage a full software development lifecycle independently — from requirements through to deployment.

Additionally, my internship at Goolee Sdn Bhd gave me real-world exposure to translating unstructured organisational data into structured digital systems, building an internal Trainer Development Management System that is actively used by the company today.

I am immediately available and genuinely excited about the opportunity to contribute to {job.company}. I would welcome the chance to discuss how my technical background and work ethic can support your team's goals.

Thank you for your time and consideration.

Warm regards,
Muhammad Syafiq Norhazwan Bin Nor Ramzi
syafiqnorhazwan@gmail.com
"""

    async def generate(self, result: MatchResult) -> str:
        """Returns the generated cover letter text."""
        if GEMINI_API_KEY and GEMINI_API_KEY != "your_gemini_api_key_here":
            log.info(f"[CoverLetter] Generating via Gemini for: {result.job.title} @ {result.job.company}")
            letter = await self._call_gemini(self._build_prompt(result))
            if letter:
                return letter
            log.warning("[CoverLetter] Gemini returned empty — falling back to template")

        log.info(f"[CoverLetter] Using template for: {result.job.title} @ {result.job.company}")
        return self._template_letter(result)

    def save(self, result: MatchResult, letter: str) -> Path:
        """Saves the cover letter as a Markdown file."""
        safe_company = re.sub(r'[^\w\s-]', '', result.job.company).strip()[:30]
        safe_title   = re.sub(r'[^\w\s-]', '', result.job.title).strip()[:30]
        date_str     = datetime.now().strftime("%Y-%m-%d")
        filename     = COVER_LETTERS_DIR / f"{date_str}_{safe_company}_{safe_title}.md"

        content = f"""# Cover Letter — {result.job.title} at {result.job.company}

> **Match Score:** {result.score}%  
> **Source:** {result.job.source}  
> **Applied:** {datetime.now().strftime('%d %B %Y')}  
> **Job URL:** {result.job.url}

---

{letter}

---
*Generated by JobAgent v2.0*
"""
        filename.write_text(content, encoding="utf-8")
        log.info(f"[CoverLetter] Saved → {filename}")
        return filename


# ══════════════════════════════════════════════════════════════
#  MODULE 5 — TELEGRAM NOTIFIER
#  Sends beautifully formatted alerts + cover letters
# ══════════════════════════════════════════════════════════════
class TelegramNotifier:
    """Sends beautifully formatted Telegram alerts using HTML formatting."""

    @property
    def _base(self):
        return f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}"

    def _build_score_bar(self, score: float) -> str:
        filled = round(score / 10)
        return "█" * filled + "░" * (10 - filled)

    def _source_emoji(self, source: str) -> str:
        return {"JobStreet": "🟢", "Indeed": "🔵", "LinkedIn": "🔷"}.get(source, "📌")

    def _esc_html(self, text: str) -> str:
        """Escape HTML special characters."""
        if not text:
            return ""
        return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")

    def build_job_alert(self, result: MatchResult) -> str:
        job = result.job
        bar = self._build_score_bar(result.score)
        src = self._source_emoji(job.source)
        all_matched = list(dict.fromkeys(
            result.matched_primary + result.matched_secondary + result.matched_context
        ))
        skills_display = " • ".join(f"<code>{self._esc_html(k)}</code>" for k in all_matched[:8])
        salary_line = f"💰 <b>Salary:</b> {self._esc_html(job.salary)}\n" if job.salary else ""
        date_line   = f"📅 <b>Posted:</b> {self._esc_html(job.posted_date)}\n" if job.posted_date else ""

        return (
            f"🚀 <b>JOB MATCH FOUND!</b> — <b>{result.score}% Match</b>\n"
            f"{src} <b>Source:</b> {self._esc_html(job.source)}\n"
            f"━━━━━━━━━━━━━━━━━━━━━━\n\n"
            f"💼 <b>Role:</b> {self._esc_html(job.title)}\n"
            f"🏢 <b>Company:</b> {self._esc_html(job.company)}\n"
            f"📍 <b>Location:</b> {self._esc_html(job.location)}\n"
            f"{salary_line}"
            f"{date_line}"
            f"\n✅ <b>Matched Skills:</b>\n{skills_display}\n\n"
            f"📊 <b>Score:</b> <code>{bar}</code> {result.score}%\n\n"
            f"<a href=\"{job.url}\">🔗 View Job Posting</a>\n"
            f"━━━━━━━━━━━━━━━━━━━━━━\n"
            f"<i>Sent by JobAgent v2.1 · {datetime.now().strftime('%d %b %Y, %I:%M %p')}</i>"
        )

    def build_cover_letter_msg(self, result: MatchResult, letter: str) -> str:
        """Formats the cover letter as a Telegram message."""
        job = result.job
        trimmed = letter[:3500] + ("..." if len(letter) > 3500 else "")
        return (
            f"📝 <b>Cover Letter Generated</b>\n"
            f"━━━━━━━━━━━━━━━━━━━━━━\n"
            f"💼 <b>{self._esc_html(job.title)} @ {self._esc_html(job.company)}</b>\n"
            f"━━━━━━━━━━━━━━━━━━━━━━\n\n"
            f"{self._esc_html(trimmed)}"
        )

    async def _post(self, payload: dict) -> bool:
        try:
            async with httpx.AsyncClient(timeout=15) as client:
                resp = await client.post(f"{self._base}/sendMessage", json=payload)
                if resp.status_code == 200:
                    return True
                log.error(f"[Telegram] API error {resp.status_code}: {resp.text[:200]}")
                return False
        except Exception as e:
            log.error(f"[Telegram] Request failed: {e}")
            return False

    def _is_configured(self) -> bool:
        if not TELEGRAM_BOT_TOKEN or TELEGRAM_BOT_TOKEN == "your_telegram_bot_token_here":
            log.warning("⚠️  Telegram not configured — skipping notification")
            return False
        return True

    async def send_job_alert(self, result: MatchResult) -> bool:
        if not self._is_configured():
            log.info(f"   [DRY RUN] Would alert: [{result.score}%] {result.job.title} @ {result.job.company}")
            return False
        return await self._post({
            "chat_id": TELEGRAM_CHAT_ID,
            "text": self.build_job_alert(result),
            "parse_mode": "HTML",
            "disable_web_page_preview": False,
        })

    async def send_cover_letter(self, result: MatchResult, letter: str) -> bool:
        if not self._is_configured():
            log.info(f"   [DRY RUN] Would send cover letter for: {result.job.title}")
            return False
        return await self._post({
            "chat_id": TELEGRAM_CHAT_ID,
            "text": self.build_cover_letter_msg(result, letter),
            "parse_mode": "HTML",
            "disable_web_page_preview": True,
        })

    async def send_summary(self, total_scraped: int, total_matched: int, duration: float):
        if not self._is_configured():
            return
        msg = (
            f"📋 <b>Job Agent Run Complete</b>\n"
            f"━━━━━━━━━━━━━━━━━━━━━━\n"
            f"🔎 Jobs Scraped: <b>{total_scraped}</b>\n"
            f"✅ Matches ≥{MATCH_THRESHOLD}%: <b>{total_matched}</b>\n"
            f"⏱️ Duration: <b>{duration:.1f}s</b>\n"
            f"🕘 Run Time: <i>{datetime.now().strftime('%d %b %Y, %I:%M %p')}</i>"
        )
        await self._post({
            "chat_id": TELEGRAM_CHAT_ID,
            "text": msg,
            "parse_mode": "HTML",
            "disable_web_page_preview": True,
        })



async def push_to_dashboard(result: MatchResult, letter: str) -> bool:
    """Pushes job match and cover letter to the PHP SQLite Web Dashboard API."""
    if not DASHBOARD_URL:
        return False
        
    payload = {
        "api_key": DASHBOARD_API_KEY,
        "title": result.job.title,
        "company": result.job.company,
        "location": result.job.location,
        "url": result.job.url,
        "source": result.job.source,
        "score": result.score,
        "salary": result.job.salary,
        "posted_date": result.job.posted_date,
        "description": result.job.description,
        "cover_letter": letter
    }
    
    try:
        async with httpx.AsyncClient(timeout=20) as client:
            resp = await client.post(DASHBOARD_URL, json=payload)
            if resp.status_code == 200:
                log.info(f"💾 Pushed match to Web Dashboard: {result.job.title} @ {result.job.company}")
                return True
            log.warning(f"⚠️ Dashboard API returned status {resp.status_code}: {resp.text[:200]}")
            return False
    except Exception as e:
        log.error(f"❌ Failed to push to Web Dashboard: {e}")
        return False


# ══════════════════════════════════════════════════════════════
#  MODULE 6 — ORCHESTRATOR
# ══════════════════════════════════════════════════════════════

async def run_agent():
    """Main async pipeline: scrape → score → cover letter → alert."""
    start = datetime.now()
    log.info("=" * 60)
    log.info("🤖 Job Agent v2.0 started")
    log.info(f"   Titles    : {', '.join(JOB_TITLES)}")
    log.info(f"   Location  : {JOB_LOCATION}")
    log.info(f"   Scrapers  : {', '.join(ENABLED_SCRAPERS)}")
    log.info(f"   Threshold : {MATCH_THRESHOLD}%")
    log.info(f"   Gemini    : {'✅ Configured' if GEMINI_API_KEY and GEMINI_API_KEY != 'your_gemini_api_key_here' else '⚠️  Not set (template mode)'}")
    log.info("=" * 60)

    profile   = ProfileParser(PROFILE_PATH)
    engine    = MatchEngine(profile)
    notifier  = TelegramNotifier()
    cl_gen    = CoverLetterGenerator(profile)

    all_jobs: list[JobListing] = []
    seen_urls: set[str]        = set()

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"],
        )

        scraper_map = {
            "jobstreet": JobStreetScraper,
            "indeed":    IndeedScraper,
            "linkedin":  LinkedInScraper,
        }
        active_scrapers = [
            scraper_map[name](browser)
            for name in ENABLED_SCRAPERS
            if name in scraper_map
        ]

        for scraper in active_scrapers:
            for title in JOB_TITLES:
                try:
                    jobs = await scraper.scrape(title, JOB_LOCATION)
                    for j in jobs:
                        dedup_key = j.url or f"{j.title}|{j.company}"
                        if dedup_key not in seen_urls:
                            seen_urls.add(dedup_key)
                            all_jobs.append(j)
                    await asyncio.sleep(2)
                except Exception as e:
                    log.error(f"[{scraper.SOURCE_NAME}][{title}] Unexpected error: {e}")

        await browser.close()

    log.info(f"\n📦 Total unique jobs scraped: {len(all_jobs)}")

    matches: list[MatchResult] = []
    for job in all_jobs:
        result = engine.score(job)
        indicator = "✅" if result.score >= MATCH_THRESHOLD else "  "
        log.info(
            f"  {indicator} [{result.score:5.1f}%] "
            f"{job.title[:38]:<38} @ {job.company[:28]:<28} ({job.source})"
        )
        if result.score >= MATCH_THRESHOLD:
            matches.append(result)

            # 1. Send job alert to Telegram
            await notifier.send_job_alert(result)

            # 2. Generate and send cover letter
            letter = await cl_gen.generate(result)
            cl_gen.save(result, letter)
            await notifier.send_cover_letter(result, letter)

            # 3. Push to Web Dashboard
            if DASHBOARD_URL:
                await push_to_dashboard(result, letter)

            await asyncio.sleep(1)  # be polite to APIs


    duration = (datetime.now() - start).total_seconds()
    log.info(f"\n✅ Matched {len(matches)}/{len(all_jobs)} jobs above {MATCH_THRESHOLD}%")
    log.info(f"⏱️  Total duration: {duration:.1f}s")

    await notifier.send_summary(len(all_jobs), len(matches), duration)


# ══════════════════════════════════════════════════════════════
#  MODULE 7 — SCHEDULER
#  Runs at 9:00 AM every weekday (Mon–Fri)
# ══════════════════════════════════════════════════════════════
def scheduled_job():
    today = datetime.now().strftime("%A")
    if today in ("Saturday", "Sunday"):
        log.info(f"🗓️  Skipping — today is {today} (weekend)")
        return
    log.info(f"⏰ Scheduled trigger — {datetime.now().strftime('%Y-%m-%d %H:%M')}")
    asyncio.run(run_agent())


def start_scheduler():
    schedule.every().day.at("09:00").do(scheduled_job)
    log.info("🗓️  Scheduler started — agent runs on weekdays at 09:00 AM.")
    log.info("   Press Ctrl+C to stop.\n")
    log.info("▶️  Running immediately on startup...")
    asyncio.run(run_agent())
    while True:
        schedule.run_pending()
        time.sleep(30)


# ══════════════════════════════════════════════════════════════
#  ENTRY POINT
# ══════════════════════════════════════════════════════════════
if __name__ == "__main__":
    if "--once" in sys.argv:
        asyncio.run(run_agent())
    else:
        start_scheduler()
