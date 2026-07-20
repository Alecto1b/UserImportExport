# User Import / Export

This scheduled-conference plugin lets a global Admin export all users with roles in the active scheduled conference, or selected users from the table, into one YAML snapshot. The snapshot can be imported into another scheduled conference without creating a live link between Leconfe installations.

## Installation

Create a ZIP whose top-level folder is `UserImportExport`, then install it from **Plugin Management** and enable it for the target scheduled conference.

## Styling

The plugin ships a self-contained native stylesheet at `public/css/user-import-export.css`. It uses only `uie-`-prefixed semantic classes and is loaded only in the scheduled-conference panel, so it does not depend on or interfere with the host application's Tailwind build.

When changing styles, update both `resources/css/user-import-export.css` and the distributed `public/css/user-import-export.css` before creating the installation ZIP.

## YAML format

```yaml
version: 1
users:
  - email: ani@example.com
    given_name: Ani
    family_name: Pratama
    public_name: Ani Pratama
    meta:
      affiliation: Universitas A
      public_name: Ani Pratama
    roles:
      - Reviewer
      - Scheduled Conference Editor
```

Every user appears once. Their role list contains only roles scoped to the source scheduled conference. The export includes non-authentication profile fields and serializable user metadata; it never includes passwords, tokens, email-verification state, sessions, timestamps, IDs, submissions, or review data.

## Import behavior

- The YAML file must be UTF-8, version `1`, and no larger than 2 MB.
- Existing users are matched by case-insensitive email. Their email is never changed; blank profile and metadata values are filled from the YAML without overwriting populated target values.
- Missing users are created directly with a generated internal password. The import sends no email, invitation, verification, or password-reset message.
- Roles are resolved from the active target scheduled conference only. Global roles such as `Admin`, conference-wide roles, and roles missing from the target are rejected even if they were added by editing the YAML.
- Any invalid user blocks the entire import. The preview lists all pending profile and role changes before confirmation.
- Import is add-only and idempotent: it never removes users, profile data, metadata, roles, or source assignments.
