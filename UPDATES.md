# SmartMinibus Updates

Last updated: 2026-10-08

## Unread notification badge

- Added a bell icon and unread-count badge to the signed-in navigation.
- Counts only notifications visible to the signed-in user. Displays `99+` when
  the unread count exceeds 99.
- Marks notifications as read when the Notifications view is opened, using the
  existing `notification_reads` table and its row-level security policy.
- Updates the badge after the user opens notifications or a driver/admin sends
  an announcement. Includes an accessible label with the unread count.
- Verified on the Netlify production site: the commuter test session displayed
  11 unread notifications; opening the view marked them read and cleared the
  badge.

## Frontend feedback and interaction improvements

- Added consistent success/error toast messages with icons, dismiss controls,
  live-region accessibility, and automatic dismissal.
- Added notification icons in notification lists and admin notification rows.
- Added loading/duplicate-submit protection to interactive forms and actions.
- Added confirmation prompts for destructive or significant actions.
- Fixed visibility behavior for elements using the HTML `hidden` attribute so
  login, registration, and other conditional fields show and hide correctly.
- Added friendlier fallback names for accounts without a saved first name.

## GPS and account workflow fixes

- Enforced the intended 10-second minimum between GPS database writes and
  prevented overlapping location writes.
- Improved GPS sharing button/status feedback and handled GPS permission errors.
- Refreshes the correct driver or commuter account list after status changes.
- Refreshes admin lists after saving buses/routes and after driver invitations.
- Added validation for route coordinates, bus capacity, and driver invitation
  input.

## Authentication and invitation security

- Updated the Auth profile-creation trigger in migrations `003` and `004` so
  existing profiles are not claimed through editable Auth metadata and
  self-registration creates only commuter profiles.
- Existing profiles must be linked by the authenticated profile-linking RPC
  after email confirmation.
- Hardened the Netlify driver-invite function with input size/type/length
  validation and clearer service errors. The service-role key remains
  server-side.
- Updated the Netlify setup notes in `README.md` to clarify the profile-link
  flow and migration order.

## Verification and deployment

- `npm run build` passed.
- JavaScript syntax checks and editor diagnostics passed.
- Tested login/registration role selection on desktop and mobile.
- Tested invitation endpoint method, missing-auth, and oversized-body guards
  with local dummy values.
- Deployed to production at <https://smartminibus-gonzaga.netlify.app>.
- Production deploy: <https://app.netlify.com/projects/smartminibus-gonzaga/deploys/6ac67bab85900856685ad861>.

Production database writes for complaints, invitations, trips, and other
administrative actions were not executed as part of these checks.
