#!/bin/bash
set -uo pipefail
IFS=$'\n\t'

echo "diff..: $(diff --version | head -n 1)"
echo "git...: $(git --version | sed -e 's/git version //')"
echo "php.... $(php --version | head -n 1)"
echo "shasum: $(shasum --version)"

echo "# wrong argument gives error"
./pcre.php --faux-long-option 1>/dev/null
[[ $? -eq 1 ]]

echo "# works with git-ls-files in quote mode"
git ls-files '*.php' | ./pcre.php 2>&1
[[ $? -eq 0 ]]


echo "# packaging with git archive"
<<EOD diff <( git archive HEAD | tar -t ) -
README.md
pcre.php
EOD
[[ $? -eq 0 ]]
