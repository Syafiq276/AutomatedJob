<?php
/**
 * cPanel Git Repository Auto-Configuration Helper
 * Configures post-receive hooks & git settings so 'git push cpanel main:master'
 * never fails with "Working directory has unstaged changes".
 */

header('Content-Type: text/plain');

echo "=== cPanel Git Remote Setup & Fix ===\n\n";

$repo_dir = dirname(__DIR__);
chdir($repo_dir);

echo "1. Checking current Git directory: " . getcwd() . "\n";

// 1. Untrack jobs.db if it was tracked in Git index
echo "2. Removing tracked database files from Git index (preserving actual db file)...\n";
echo shell_exec("git rm --cached web/jobs.db 2>&1") . "\n";

// 2. Configure Git to allow updating current branch automatically
echo "3. Setting git config receive.denyCurrentBranch = updateInstead...\n";
echo shell_exec("git config receive.denyCurrentBranch updateInstead 2>&1") . "\n";

// 3. Create or update post-receive hook to auto-checkout cleanly
$hook_dir = $repo_dir . '/.git/hooks';
$hook_file = $hook_dir . '/post-receive';

if (!is_dir($hook_dir)) {
    mkdir($hook_dir, 0755, true);
}

$hook_script = "#!/bin/sh\n" .
               "# Auto-checkout working directory cleanly on git push\n" .
               "export GIT_WORK_TREE=\"" . $repo_dir . "\"\n" .
               "git checkout -f master 2>&1\n";

file_put_contents($hook_file, $hook_script);
chmod($hook_file, 0755);

echo "4. Created .git/hooks/post-receive hook successfully!\n";

// 4. Run one-time clean to clear current dirty state
echo "\n5. Performing initial clean on remote repo...\n";
echo shell_exec("git checkout -- . 2>&1") . "\n";
echo shell_exec("git clean -fd 2>&1") . "\n";

echo "\n✅ Setup complete! You can now run 'git push cpanel main:master' smoothly without unstaged changes errors.\n";
