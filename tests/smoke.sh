#!/bin/bash
set -uo pipefail
IFS=$'\n\t'

echo "# wrong argument gives error"
./pcre.php --faux-long-option 1>/dev/null
[[ $? -eq 1 ]]

echo "# works with git-ls-files in quote mode"
git ls-files '*.php' | ./pcre.php 2>&1
[[ $? -eq 0 ]]
