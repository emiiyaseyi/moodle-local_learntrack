# Contributing to LearnTrack

Thank you for your interest in contributing to LearnTrack!

## How to contribute

### Reporting bugs
Please open a GitHub Issue with:
- Your Moodle version
- Your PHP version
- Steps to reproduce
- Expected vs actual behaviour
- Any error messages or screenshots

### Suggesting features
Open a GitHub Issue tagged `enhancement` describing:
- The problem you're trying to solve
- Your proposed solution
- Who would benefit from this feature

### Pull requests
1. Fork the repository
2. Create a branch: `git checkout -b feature/your-feature-name`
3. Follow Moodle coding standards: https://moodledev.io/general/development/policies/codingstyle
4. Test on Moodle 4.5+ with PHP 8.1+
5. Ensure no new SQL uses database-specific syntax (use Moodle DML API only)
6. Update `CHANGES.md` with your changes
7. Submit a pull request

## Coding standards
- Follow [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle)
- All global PHP functions inside namespaced classes must use `\function_name()` prefix
- No `addRule('required')` on form fields that use `hideIf()` (MDL-73242)
- Use `PARAM_RAW` (not `PARAM_INT`) for autocomplete multi-select fields
- No database-specific SQL — use Moodle DML API only

## Contact
Michael Adeniran — michaeladeniransnr@gmail.com
