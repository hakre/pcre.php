#!/bin/bash
# this file is part of pcre.php
set -uo pipefail
IFS=$'\n\t'

echo "diff..: $(diff --version | head -n 1)"
echo "git...: $(git --version | sed -e 's/git version //')"
echo "php.... $(php --version | head -n 1)"
echo "shasum: $(shasum --version)"
echo "awk...: $(awk --version | head -n 1)"
echo "sort..: $(sort --version | head -n 1)"

echo "# -C works and fails"
./pcre.php -C . -C build -C ..  -C tests -C neverland
[[ $? -eq 1 ]] || exit 1

echo "# wrong argument gives error"
./pcre.php --faux-long-option 1>/dev/null
[[ $? -eq 1 ]] || exit 1

echo "# works with git-ls-files in quote mode"
git ls-files '*.php' | ./pcre.php 2>&1
[[ $? -eq 0 ]] || exit 1

echo "# works with git-ls-files in -z mode"
git ls-files -z '*.php' | ./pcre.php 2>&1
[[ $? -eq 0 ]] || exit 1

echo "# injecting null bytes in paths does not fatal error"
git ls-files -z 'pcre.php' | ./pcre.php
[[ $? -eq 0 ]] || exit 1

echo "# files with no newline at the end of file are skipped"
git ls-files '*/*newline*.php' | pcre.php '/FOO/'
[[ $? -eq 0 ]] || exit 1

echo "# works with including (unit tests)"
tests/include.php
[[ $? -eq 0 ]] || exit 1

echo "# packaging with git archive (worktree attributes)"
<<EOD diff <( git archive --worktree-attributes HEAD | tar -t ) -
README.md
pcre.php
EOD
[[ $? -eq 0 ]] || exit 1

echo "# packaging with git archive"
<<EOD diff <( git archive HEAD | tar -t ) -
README.md
pcre.php
EOD
[[ $? -eq 0 ]] || exit 1

echo "# standard search: empty stdin (/dev/null) should not cause error"
</dev/null ./pcre.php  '~test~' 2>&1 1>/dev/null | grep -q 'error'
[[ $? -ne 0 ]] || exit 1

echo "# file-match: empty stdin (/dev/null) should not cause error"
</dev/null ./pcre.php  --file-match '~test~' 2>&1 1>/dev/null | grep -q 'error'
[[ $? -ne 0 ]] || exit 1

echo "# file-match: empty path should cause error"
<<EOD cat | ./pcre.php --file-match '~test~' 2>&1 1>/dev/null | grep -q 'error'

EOD
[[ $? -eq 0 ]] || exit 1

echo "# standard-search: empty path should cause error"
<<EOD ./pcre.php '~test~' 2>&1 1>/dev/null | grep -q 'error'

EOD
[[ $? -eq 0 ]] || exit 1

echo "# newline at very next line should not halt reading paths"
< <(
  awk 'BEGIN { while (z++ < 4096) printf "="; printf "\n"; }';
  echo "pcre.php";
) ./pcre.php '~~' 2>/dev/null | grep -q '^pcre.php$'
[[ $? -eq 0 ]] || exit 1

echo "smoke tests done."
