<?php
header('Content-Type: text/plain');
echo "--- Git Status BEFORE Clean ---\n";
echo shell_exec("git status 2>&1") . "\n";
echo "-------------------------------\n";
echo "Discarding local modifications...\n";
echo shell_exec("git checkout -- . 2>&1") . "\n";
echo "Cleaning untracked files...\n";
echo shell_exec("git clean -fd 2>&1") . "\n";
