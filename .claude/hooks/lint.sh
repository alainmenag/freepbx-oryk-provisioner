#!/usr/bin/env bash
#
# PostToolUse hook: lint the file Claude Code just wrote. An error goes back
# to Claude (exit 2) so it is fixed before anything else is. Silent when the
# linter for that kind of file is not installed.

file=$(sed -n 's/.*"file_path" *: *"\([^"]*\)".*/\1/p' | head -1)
[ -n "$file" ] && [ -f "$file" ] || exit 0

case "$file" in
	*.php | */bin/oryk-fail2ban-sync | */bin/oryk-signup-sweep)
		command -v php >/dev/null || exit 0
		out=$(php -l "$file" 2>&1) || { echo "$out" >&2; exit 2; }
		;;
	*/assets/scripts/*.js)
		command -v node >/dev/null || exit 0
		out=$(node --check "$file" 2>&1) || { echo "$out" >&2; exit 2; }
		;;
esac

exit 0
