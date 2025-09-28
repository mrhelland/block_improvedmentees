# Improved Mentees Block

This block provides an enhanced mentee tracking experience in Moodle, designed to be used with Moodle’s built‑in **mentor system** introduced in 4.x.

## Current Status
- ⚠️ Maturity: This plugin is currently marked as BETA.
- ✅ Updated to work with Moodle 4.5+
- ✅ No longer depends on the old **DB folder** from previous versions
- ✅ Refactored to avoid **deprecated API calls** (`assign_get_assignments`, `assign_get_submission_status`)
- ✅ Uses modern Moodle APIs:
  - `get_fast_modinfo()` for loading course module information
  - `assign` class (from `mod/assign/locallib.php`) for assignment data and user submissions
  - Moodle DML layer (`$DB->get_record`, `$DB->get_records`, etc.) for safe SQL
- ✅ Defensive checks have been added to renderer and helper functions

## Capabilities

- Show a mentor (user with the appropriate role assignment) a list of their mentees.
- For each mentee:
  - Display upcoming or overdue assignments where no valid submission exists.
  - Show basic user information (firstname, lastname, username).
- Designed to be resilient: if the new API fails, the block falls back to SQL‑based queries for compatibility.

## Technical Notes

- The plugin uses Moodle’s **context system** to determine mentor/mentee relationships (`CONTEXT_USER`).
- Assignment information is fetched with the `assign` class and submission status is checked with `get_user_submission()`.
- For robustness, a **fallback SQL path** exists in case of API issues.
- All SQL queries are parameterized and use Moodle’s `{tablename}` prefix system for compatibility across installations.

## Installation

1. Place this plugin in `blocks/improved_mentees/`
2. Run Moodle upgrade (`Site administration → Notifications`)
3. Add the block to a dashboard or course page.

## Requirements

- Moodle 4.5+ recommended
- PHP 8.0+

---

Maintained version: **2025 refactor with non‑deprecated APIs**.
