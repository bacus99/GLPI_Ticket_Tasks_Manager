# Changelog

All notable changes to **Tasks Manager** are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## [1.6.1] — 2026-05-27

### Security
- **CVE-pending — three IDOR / missing per-ticket authorization fixes**
  in the AJAX endpoints. Each previously gated only on the *global*
  profile right (`ticket UPDATE` or `plugin_tasksmanager_workflows
  UPDATE`) without verifying the caller had access to the specific
  ticket being targeted. Because ticket IDs and `ticket_workflows_id`
  values are sequential auto-increments, this allowed a user scoped
  to Entity A to enumerate IDs and act on tickets in Entity B.

  All three endpoints now resolve the parent ticket and gate on
  `Ticket::can($id, READ|UPDATE)` (which walks the actor / entity
  visibility chain via `Session::haveAccessToEntity()`):

  - **`ajax/taskstate.php?action=get_states`** (HIGH) — previously
    leaked task-state rows (notes, assignees, workflow step data,
    progress, due dates) for any ticket. Now requires `Ticket READ`
    on the target.
  - **`ajax/workflow.php?action=remove_from_ticket`** (HIGH) — could
    cancel active workflows on any ticket. Now mirrors the
    `apply_to_ticket` pattern (`getFromDB` + `canUpdateItem`).
  - **`ajax/workflow.php?action=skip_current_step`** and
    **`?action=restart_current_step`** (MEDIUM) — could force-advance
    or re-instantiate workflow steps on any ticket, triggering task
    creation and group reassignment side-effects. Both now resolve
    the parent ticket via new helper `Workflow::getTicketIdForWorkflow()`
    and gate on `canUpdateItem()` before the workflow state mutation.

### Notes
- No schema change. No user-facing UI change. Pure server-side
  authorization hardening — safe to drop in.
- Behaviour on legitimate operations is unchanged; only IDOR attempts
  now return `403 Access denied`.

## [1.6.0] — 2026-05-26

### Added
- **TimelineTicket-compatible "Begin" and "Delay" columns** on the
  Workflow tab's History card. Mirrors the semantics from
  [pluginsGLPI/timelineticket](https://github.com/pluginsGLPI/timelineticket)'s
  debug reports:
  - **Begin** — elapsed time from ticket creation to the event
  - **Delay** — elapsed time since the previous workflow event
  Both formatted via `Html::timestampToString()` — `"19 days 2 hours
  59 minutes 16 seconds"` style — so they read identically to
  timelineticket's existing AssignGroup / AssignUser / AssignState
  views.
- A small "TimelineTicket compatible" badge appears in the History
  card header when any of timelineticket's tables
  (`glpi_plugin_timelineticket_assigngroups`,
  `_assignusers`, `_assignstates`) are detected — confirms the
  columns share the same semantics as that plugin's reports.

### Notes
- No DB coupling with timelineticket: we compute begin/delay locally
  from our own `glpi_plugin_tasksmanager_workflow_events` table. The
  two plugins can be installed (or not) independently.
- Workflow events you'll see with timing now include every audit
  point: workflow start, deferred-for-approval, each step start,
  every routing decision, step skipped / restarted, and workflow
  completed. Makes it easy to spot "we waited 4 days at step 2" or
  "step 3's task took 10 minutes between creation and done".

## [1.5.10] — 2026-05-26

### Changed
- **Form-question dropdown in the rules editor is now auto-scoped to
  forms that reference this workflow.** Previously it listed every
  question defined anywhere in the GLPI instance, which became unusable
  with more than a couple of forms. The `list_form_questions` endpoint
  now accepts a `workflows_id` parameter and:
  1. Scans `glpi_forms_destinations_formdestinations.config` JSON for
     entries with `tasksmanager_workflow.value` matching the workflow
     being edited.
  2. Restricts the question query to forms (via
     `glpi_forms_sections`) whose destinations reference this workflow.
  3. Falls back to the full list when no form references the workflow
     yet — so you can still build rules ahead of wiring up the
     destination.
- The rules panel now shows a small hint indicating which mode is in
  effect (`Showing only questions from forms that reference this
  workflow.` vs `Showing every defined question. Assign this workflow
  to a form\'s Ticket destination to narrow the list.`).

## [1.5.9] — 2026-05-26

### Changed
- **Workflow tab now surfaces pending workflows.** Previously, when the
  approval gate deferred a workflow (waiting for ticket validation),
  the Workflow tab still showed "No active workflow on this ticket" —
  identical to the empty state, hiding the fact that anything had
  happened. Now a yellow "Workflow X — waiting for approval" banner
  appears when a `pending_workflows` row exists, with the queued time
  and the audit log below it.
- The audit log (History card) is now rendered in **all** empty states
  (no active workflow + no pending + no completed) so users can always
  inspect past events even after a workflow finishes or is removed.

## [1.5.8] — 2026-05-26

### Changed
- **Defense-in-depth approval gate via `register_shutdown_function`.**
  The synchronous check in `plugin_tasksmanager_ticket_add` now also
  schedules a shutdown-time recheck (`plugin_tasksmanager_recheck_pending_apply`).
  By the time PHP shuts down for the request, every Forms destination
  field (`ValidationField`, `ITILTaskField`, etc.) has finished its
  post-add processing and the DB is in its final state. The shutdown
  function re-reads `global_validation` + counts waiting
  `TicketValidation` rows authoritatively, and:
  - applies the pending workflow if no approval is now visible, or
  - leaves the pending record alone for `ticket_update` to consume on
    ACCEPT.

  Safe to run alongside the synchronous apply because
  `Workflow::applyToTicket` refuses to create a second active workflow.

### Added
- `workflow_pending_recheck` / `workflow_applied_recheck` audit events
  log the shutdown-time decision with the final validation state, so
  any divergence from the synchronous check is visible in the History
  card.

## [1.5.7] — 2026-05-26

### Changed
- **"Skipped" badge for steps the workflow jumped over.** The Workflow
  tab's step-list table previously rendered every step with
  `step_order < current_step` as "Done" — even steps that were never
  instantiated because a routing rule (or `default_goto`) jumped past
  them. The renderer now cross-checks against
  `glpi_plugin_tasksmanager_taskstates`: if a step in the past has no
  taskstate row for this `ticket_workflows_id`, it shows a yellow
  "Skipped" badge with a track-next icon instead of the green "Done"
  badge. Makes conditional routing visually obvious in the steps list,
  matching what the History audit trail already shows.

## [1.5.6] — 2026-05-25

### Fixed
- **Form-answer rules now resolve Radio / Dropdown / Item options to
  their human labels before comparison.** Previously the engine read the
  raw `raw_answer` value out of the AnswersSet JSON, which for selectable
  question types is a stable internal UUID (e.g. `["1686595752"]`), not
  the visible label ("PRD", "DEV", …). A rule of
  `form:16 contains "PRD"` therefore never matched.

  The resolver now goes through GLPI's own machinery —
  `Glpi\Form\Question::getById($question_id)->getQuestionType()->formatRawAnswer($raw, $question)`
  — which performs the UUID-to-label translation (including any active
  FormTranslation), foreign-key dereferencing for `QuestionTypeItem` /
  `QuestionTypeItemDropdown`, and joining of multi-select arrays into a
  comma-separated string. The result is HTML-stripped and lowercased to
  feed `contains` / `eq` / `not_contains` / `neq` consistently.

  Falls back to the previous raw-value path (flattened to a string via
  `array_walk_recursive`) when the Forms classes aren't loadable —
  keeps behaviour for `QuestionTypeShortText` / `LongText` / `Number` /
  `DateTime` unchanged, since their raw_answer already is the visible
  value.

### Notes
- This also makes `form:<id>` work for nested-object answers like
  `{"items_id":"57083","itemtype":"Computer"}` (Item question types) —
  `formatRawAnswer` returns the item's friendly name, against which you
  can run `contains "Server"` etc.

## [1.5.5] — 2026-05-25

### Fixed
- **`form:<question_id>` rules now actually work.** The 1.5.0 form-answer
  resolver looked up the AnswersSet → ticket relationship in
  `glpi_forms_answerssets_formdestinationitems`, which does not exist
  in GLPI 11. The correct table name (per
  `install/mysql/glpi-11.0.4-empty.sql` and the
  `Glpi\Form\Destination\AnswersSet_FormDestinationItem` CommonDBRelation)
  is `glpi_forms_destinations_answerssets_formdestinationitems` — note
  the extra `destinations_` segment.

  Symptom: routing rules of the form `form:N contains X` always logged
  `field_unresolved` in the `step_routed` audit event and silently fell
  through to the linear next step, even when the ticket *was* created
  from a form and the question *did* have a matching answer.

  Now resolved end-to-end: the column structure (`forms_answerssets_id`,
  `itemtype`, `items_id`) and `answers` JSON shape
  (`[{question_id, question_label, raw_question_type, raw_answer}, …]`,
  per `Glpi\Form\AnswersHandler\AnswersHandler::createAnswers`) are
  both honoured.

## [1.5.4] — 2026-05-25

### Changed
- **Permissive validation-key sniffing in the approval gate.** Instead
  of looking for `_validation_targets` / `_add_validation` by exact
  name, the hook now scans `$item->input` for *any* key whose name
  contains "validation" or "approval" (with a non-empty value). This
  catches paths we haven't anticipated — plugin-added validation
  schemes, future Forms changes, classic ticket creation with the
  `_validation` legacy key — without needing a code change every time.

### Added
- `workflow_pending` / `workflow_applied_immediate` audit events now
  include `validation_keys_seen` (which keys matched) and `input_keys`
  (the full list of input keys present at hook time, truncated to
  500 chars). The History card surfaces both as small lines under the
  event so you can verify whether Forms passed the validation marker
  by the time our hook ran.

## [1.5.3] — 2026-05-25

### Fixed
- **Workflow no longer applies before approval is granted on
  Forms-created tickets.** When a form had a `ValidationField`
  configured, the workflow would sometimes start (creating its first
  task — and on subsequent task completions, advancing further) before
  the requester approved the request. Root cause: `$item->fields`
  inside `Hooks::ITEM_ADD` can lag behind the actual DB state because
  the Forms `ValidationField` creates the validation request in
  `post_addItem`, so `global_validation` was still `NONE` when we
  read it.

  The approval gate now performs three independent checks and defers
  the apply if **any** of them indicates approval is needed:
  1. Re-reads `global_validation` directly from `glpi_tickets` (not
     from the potentially stale `$item->fields`)
  2. Counts `WAITING`-status rows in `glpi_ticketvalidations` for
     this ticket
  3. Inspects `$item->input['_validation_targets']` (the prepared
     input the Forms ValidationField uses to schedule validations)

  The pending workflow record stays in place; `ticket_update` consumes
  it as soon as `global_validation` transitions to `ACCEPTED`.

### Added
- New audit event types for diagnosing the approval flow:
  - `workflow_pending` — logged when we defer the apply because an
    approval is required. Details capture all three signals
    (`global_validation`, `pending_validations`, `input_has_validation`)
    so you can see *why* we deferred.
  - `workflow_applied_immediate` — logged when we apply right away
    because no approval was needed.

  Both surface on the History card via the same renderer as
  `step_routed`, so you can trace the full lifecycle from ticket
  creation onward.

## [1.5.2] — 2026-05-25

### Added
- **Routing-decision audit trail (`step_routed` event).** Every time
  the engine advances a workflow (auto on task-done or via Skip), it
  now writes a `step_routed` audit entry capturing the *full* decision
  trace: how many rules were tried, the actual ticket value each rule
  saw, why each non-matching rule was skipped
  (`invalid_rule` / `field_unresolved` / `op_no_match` /
  `goto_invalid_or_backward`), and the final routing decision
  (`rule_match` / `default_goto` / `default_end` / `linear` /
  `workflow_end`). Surfaced inline on the History card so you can
  diagnose "why did step 3 run instead of step 4?" without poking at
  the JSON column.

- `Workflow::resolveNextStep` now accepts an optional `array &$trace`
  reference parameter that callers populate and pass to `logEvent`.
  Default value is `[]`, so external callers (if any) keep working.

## [1.5.1] — 2026-05-25

### Fixed
- **Re-trigger schema migration for installs that already had 1.5.0.**
  When the `default_goto_step_id` column was added during 1.5.0 development,
  installs that had already recorded "1.5.0 installed" in GLPI's plugin
  registry didn't re-run `plugin_tasksmanager_install()`, leaving the
  column missing and producing
  `Unknown column 'wfs.default_goto_step_id' in 'field list'` when opening
  the workflow editor. Bumping to 1.5.1 forces GLPI's plugin loader to
  call the install hook again; the idempotent `$DB->fieldExists()` guard
  there now adds the column on existing installs and is a no-op on fresh
  ones.

### Notes
- No code changes beyond the version bump and this CHANGELOG entry — the
  install hook already had the correct migration; it just wasn't being
  invoked.

## [1.5.0] — 2026-05-25

### Added
- **Conditional next step (per-step routing rules).** Each workflow step
  can now carry an ordered list of routing rules that override the
  default linear "next step by step_order" behaviour. When a step's
  task is marked Done, the engine evaluates the current step's rules in
  order; the first match wins and the workflow jumps to that rule's
  `goto_step_id`. No match = legacy sequential fallthrough.

  Supported fields:
  - `content` — ticket description (HTML stripped, case-insensitive)
  - `name` — ticket title (case-insensitive)
  - `form:<question_id>` — answer to a GLPI Forms question on the
    AnswersSet that produced the ticket (best-effort: silently skipped
    when GLPI Forms is not installed or the ticket wasn't created from
    a form)

  Supported operators: `contains`, `not_contains`, `eq`, `neq`
  (all case-insensitive end-to-end).

  Loop guard: a rule may only target a step whose `step_order` is
  strictly greater than the current step's. Backward jumps are
  silently ignored.

  Wired into both the auto-advance hook (`plugin_tasksmanager_item_update`)
  and the admin Skip action (`Workflow::skipCurrentStep`) so behaviour
  is consistent in both code paths.

- **Else / default branch.** Each step now also carries a
  `default_goto_step_id` (signed INT). When no rule matches, the engine
  consults this value before falling back to the linear next step:
  - `0`  (default) — sequential next step (legacy behaviour preserved)
  - `-1` — end the workflow immediately
  - `>0` — jump to that specific step id (forward-only; invalid /
    deleted / backward targets silently fall through to linear)
  Lets you model "When field A = 0 go to step 3, else step 2" or
  "When field A = X go to step 5, else end the workflow."

- **Per-step "Routing rules (N)" editor** under each step card on the
  workflow form. Expandable inline editor with field/op/value/goto
  inputs and an Add rule button. Saves on blur/change via XHR
  (`save_step_rules`). Form-question dropdown is lazy-loaded on first
  expand (`list_form_questions`).

- **Schema:** new columns on `glpi_plugin_tasksmanager_workflow_steps`
  (idempotent upgrade migrations):
  - `next_step_rules TEXT NULL` — JSON-encoded routing rules
  - `default_goto_step_id INT NOT NULL DEFAULT 0` — else target

### Changed
- Step rows on the editor now carry `data-step-order` and `data-rules`
  attributes so the goto-step dropdown can filter to forward-only
  jumps and rules survive Sortable reorders.
- `add_step` AJAX now returns `step_order` alongside `step_id`.
- Steps list is re-keyed with `array_values()` after `iterator_to_array`
  to keep `$i` positional in the foreach.

## [1.4.0] — 2026-05-15

### Added
- **Skip / Restart current step (admin actions).** Two new buttons on the
  ticket's Workflow tab let an admin force-advance past a stuck step
  ("Skip step") or re-create the current step's task ("Restart step").
  Both mark any lingering TicketTask as Done first, then either advance
  `current_step` (and create the next step's task) or re-instantiate the
  current step. New audit events: `step_skipped`, `step_restarted`.
  New AJAX actions: `skip_current_step`, `restart_current_step`. Requires
  `plugin_tasksmanager_workflows : UPDATE`.

- **Visual workflow builder (vertical flowchart).** The workflow editor's
  step list is now a vertical card-based flowchart with chevron connectors
  between steps and a drag handle on each card. Drag-reorder uses
  **SortableJS** (bundled in GLPI 11 at `public/lib/sortablejs.js`),
  replacing the up/down arrow buttons. The legacy table layout is gone.
  Each step card shows the number badge, the clickable task-template name,
  and the expandable template-comment editor.

### Changed
- Removed `tmMoveStep` JS helper (replaced by SortableJS).
- CSS additions for `.tm-flow*` classes scoped to the builder.

## [1.3.19] — 2026-05-15

### Changed
- **AJAX response contract aligned with `GLPI-Shared/rules/glpi-plugin-api.md`.**
  All plugin endpoints (`ajax/workflow.php`, `ajax/taskstate.php`) now return
  `{ ok: bool, error?: string, data?: object }` instead of the previous
  `{ success: bool, message: string }`. Error responses additionally use
  proper HTTP status codes (400 missing/invalid input, 403 forbidden,
  404 not found, 500 server error) so transport-level tooling can react
  appropriately. JS callers in `src/TaskDashboard.php`,
  `front/workflow.form.php`, and `public/js/tasksmanager.js` updated to
  read the new shape.
- Composer package name corrected to `bacus99/glpi-tasksmanager` (matching
  the shared `bacus99/glpi-<plugin-slug>` convention).
- `CLAUDE.md` updated to reflect the corrected composer name and document
  the intentional reliance on `CheckCsrfListener` (no explicit
  `Session::validateCSRF` calls in AJAX endpoints).
- `.glpiignore` now excludes `CLAUDE.md` so the dev guide isn't shipped
  in release tarballs.

## [1.3.18] — 2026-05-15

### Added
- **Tasks Manager rights tab on GLPI Profiles**
  (Administration → Profiles → *profile* → **Tasks Manager**). Lets admins
  grant per-profile rights instead of relying on the catch-all `config`
  right. New right: `plugin_tasksmanager_workflows` with granular
  Read / Update / Create / Delete / Purge permissions, rendered via
  GLPI's native `displayRightsChoiceMatrix`.
- Install/upgrade automatically declares the right and grants full
  permissions to the super-admin profile (id 4) so the installer can
  use the plugin out of the box.
- Uninstall removes the right from every profile.

### Changed
- All workflow-management permission checks (workflow list / form /
  AJAX endpoints / TaskDashboard "Create a workflow" link) switched
  from `Session::checkRight('config', …)` to
  `Session::checkRight('plugin_tasksmanager_workflows', …)`.

## [1.3.17] — 2026-05-15

### Changed
- The "description" textarea on each workflow step is now bound to the
  **task template's `comment` field**, not a per-step value. Editing it
  updates `glpi_tasktemplates.comment` via `TaskTemplate->update()` and
  applies to every workflow that uses the same template. New AJAX action:
  `update_template_comment`. The textarea label is now "Add / Edit
  template comment" with a small hint about its scope.
- `Workflow::applyStep` no longer overrides the new task's content with
  the step's description — task content always comes from the template's
  `content` field, so behavior matches GLPI's own task-template flow.

### Notes
- The `glpi_plugin_tasksmanager_workflow_steps.description` column added
  in 1.3.15 is now unused but left in place to keep the upgrade idempotent.

## [1.3.16] — 2026-05-15

### Added
- Task-template name in the workflow editor steps table is now a clickable
  link that opens the GLPI task template's edit form in a new tab. Saves a
  context-switch when reviewing what each step actually does.

## [1.3.15] — 2026-05-15

### Added
- **Per-step description.** Each workflow step now has an optional
  description field (rich text, stored as TEXT on
  `glpi_plugin_tasksmanager_workflow_steps.description`). When set, it
  overrides the task template's body when the step instantiates — so you
  can reuse a single task template across workflows and supply
  step-specific runbook instructions per workflow. Idempotent upgrade
  migration adds the column to existing installs.
- Inline expand/collapse description editor on the workflow form, saved
  via XHR on blur. New AJAX action: `update_step_description`.

### Changed
- Plugin's workflow panel now overrides `--tblr-secondary` to `#d6dee8`
  (lighter blue-gray for the "Pending" badge) and `--tblr-success` to
  `#57f06f` (brighter green for the "Done" badge). Scoped to
  `.tasksmanager-workflow-panel` so global GLPI badges are unaffected.

## [1.3.14] — 2026-05-15

### Added
- **Clone workflow.** A "Duplicate" button on the workflow list page (next to
  Edit and Delete) creates a copy of the workflow's metadata and all its
  steps under a new "(copy)" name, then opens the new workflow's editor.
- **Audit log.** Every workflow event (apply, step start, complete, remove)
  is now recorded with the user, timestamp, and JSON details payload. A new
  "History" card on the Workflow tab shows the last 12 events for the
  ticket. New table `glpi_plugin_tasksmanager_workflow_events` (created on
  install/upgrade).

## [1.3.13] — 2026-05-15

### Added
- Per-workflow checkbox **"Assign the ticket to each step's task team"**
  (default on, backward-compatible). When unchecked, advancing the workflow
  only sets the new task's tech/group from the template — the ticket's own
  assignment is left untouched. Adds `assign_ticket_to_task` to
  `glpi_plugin_tasksmanager_workflows` with an idempotent upgrade migration.

## [1.3.12] — 2026-05-15

### Fixed
- Workflow tab status badges (Done / In progress / Pending) now render with
  white text even on GLPI 11 themes that set `--tblr-badge-color` to a dark
  colour. The plugin's CSS is now registered via `ADD_CSS` (it wasn't being
  loaded at all before) and contains a scoped override.

## [1.3.11] — 2026-05-15

### Fixed
- Task template values of `-1` for `users_id_tech` / `groups_id_tech` (GLPI's
  "no specific user/group" placeholder) no longer cause a MySQL
  "Out of range value" error on the `INT UNSIGNED` column. They are now
  treated as empty for both the new task input and the ticket actor swap.

## [1.3.10] — 2026-05-15

### Fixed
- Editing an existing followup/answer in the timeline no longer reloads the
  page mid-edit. Auto-refresh now triggers from a server-set response header
  (`X-TM-Workflow-Advanced: 1`) that's only sent when our hook actually
  advances a workflow step. The previous URL-pattern matcher fired on any
  POST to `/ajax/timeline.php`, which caught followup edits as well.

## [1.3.9] — 2026-05-15

### Changed
- Auto-refresh logic now matches GLPI 11's actual save path: any successful
  POST/PUT/PATCH to `/ajax/timeline.php` (or any `tickettask` URL) on a
  ticket page schedules a reload. The previous `state=2` body check was
  too strict — GLPI sends task saves as multipart FormData where the body
  matcher could fail to read the state value reliably.
- Form-submit fallback added: full-page POST submits to a save URL with
  `state=2` set a sessionStorage flag that forces a reload on next page.

## [1.3.8] — 2026-05-15

### Changed
- `workflow-refresh.js` rewritten to be more aggressive: broader URL
  pattern list (now includes `timeline`, `common.tabs`, etc.) and a DOM
  fallback that observes the timeline for a task switching to Done.
- Verbose tracing available via `localStorage.setItem('tm_debug','1')` —
  every relevant XHR / fetch is logged to the browser console.

## [1.3.7] — 2026-05-15

### Fixed
- `workflow-refresh.js` now lives under `public/js/` so GLPI 11's static
  asset serving can find it. Previously the file was at top-level `js/`
  and returned 404 in the browser, meaning the page never auto-reloaded
  after a task was marked Done.

## [1.3.6] — 2026-05-14

### Fixed
- Ticket sidebar now reflects the new step's assigned group. Previous versions
  changed the assignment via direct inserts into `glpi_groups_tickets` /
  `glpi_users_tickets`, which bypassed GLPI 11's actor cache and left the
  sidebar showing the old group even though the underlying tables were
  correct. Actor swap now goes through `Ticket::update(['_actors' => …])`
  while preserving requesters and observers untouched.

## [1.3.5] — 2026-05-14

### Fixed
- Restored `js/workflow-refresh.js` (the file was missing from the build, so
  the ticket page didn't reload after a task was marked Done — the workflow
  *had* swapped the ticket's assigned group on the server, but the user was
  still looking at the pre-change sidebar).

## [1.3.4] — 2026-05-14

### Changed
- Version bump to trigger the database upgrade path on existing installs
  (adds `groups_id_completion` to `glpi_plugin_tasksmanager_workflows`).

## [1.3.3] — 2026-05-14

### Added
- Completion banner on the Workflow tab: once the last step is done the panel
  shows "Workflow *name* completed." with a timestamp, instead of falling
  back to a bare "No active workflow" line.
- Workflow definition now accepts an optional **completion group**. When the
  last step is marked done, the ticket's ASSIGN group is replaced with this
  one so the closing team (typically L2/L3) inherits the ticket.
- Wrench icon on **Setup → Plugins** now opens the workflow list directly
  instead of the generic config page.

### Changed
- Author is now Christian Bernard only.

### Fixed
- Step counter on the Workflow tab no longer shows inflated numbers like
  "11 / 4". Caused by `iterator_to_array` preserving the GLPI row IDs as
  array keys; now re-keyed with `array_values()` so `$i` is positional.

## [1.3.2] — 2026-05-14

### Added
- Auto-refresh of the ticket page when a workflow task is marked Done, so the
  user always sees the new step's task and refreshed actors.
- Defensive guards around the GLPI Forms destination registration so the
  plugin no longer fails to load when boot order delays the form classes.

### Changed
- The ticket's *assigned tech / group* is now swapped **before** the next
  step's TicketTask is created, so the "new task" notification reaches the
  new step's team — not the previous one.

### Fixed
- Double-fire prevention when a Save replays a task that's already Done.
- New workflow tasks are now created with state **To do** (1) instead of
  **Information** (0), so they appear in the user's task list.

## [1.3.1] — earlier

- Initial public release of the workflow engine.
- Workflow builder under **Tools**, per-ticket **Workflow** tab.
- GLPI Forms destination integration via `FormDestinationManager`.
