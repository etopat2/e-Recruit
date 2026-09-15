# System Structure & UI Audit

Audit date: 2026-09-14. This is a discovery snapshot of the current working tree. No application code, dependency, configuration, or database change is part of this audit.

## 1. Stack & repo overview

### Reusable values

- `REPO_PATH = C:\xampp\htdocs\PROJECTS\e-Recruit`
- `FRONTEND_STACK = Vue 3.5.42 + TypeScript 6.0.2 + Vue Router 5.3.0 + Pinia 4.0.3 + Vite 8.2.2`
- `COMPONENT_LIBRARY = none`

### Languages, frameworks, and tooling

| Area | Current implementation | Evidence |
| --- | --- | --- |
| Browser application | Vue 3 single-page application using TypeScript, Vue Router, and Pinia. The lockfile resolves Vue 3.5.42, Vue Router 5.3.0, and Pinia 4.0.3. | `apps/web/package.json:17-46`; `apps/web/package-lock.json:7404-7406,9189-9191,9242-9244`; `apps/web/src/main.ts:1-9` |
| Frontend build/package manager | Vite 8.2.2, `vue-tsc`, ESLint, npm, and `package-lock.json`; the container uses Node 22.23.0. | `apps/web/package.json:6-15,24-46`; `apps/web/package-lock.json:8983-8985`; `apps/web/Dockerfile:1-14` |
| API | PHP 8.5 container running Laravel 13.29.0. The API is JSON-first and versioned under `/api/v1`. | `apps/api/Dockerfile:1-14,33-45`; `apps/api/composer.lock:1694-1699`; `apps/api/routes/api.php:30-40` |
| API authentication | Laravel Sanctum 4.3.3 opaque personal-access tokens, not JWTs. | `apps/api/composer.lock:1980-1985`; `apps/api/app/Models/User.php:13-22`; `apps/api/routes/api.php:40-46` |
| Database/runtime services | PostgreSQL 17.6 is the primary database; Redis 7.4.5 supplies cache/runtime infrastructure; MinIO supplies private S3-compatible object storage. | `docker-compose.yml:3-20,22-54,119-148` |
| Document worker | Python 3.12 FastAPI service with OpenCV, Pillow, PyMuPDF, and Tesseract bindings. | `services/document-worker/pyproject.toml:1-18`; `docker-compose.yml:87-105` |

### Styling

The frontend uses one global plain-CSS stylesheet, not Tailwind, Bootstrap, CSS Modules, Sass, or CSS-in-JS. Color tokens are CSS custom properties in `:root`; spacing, radii, and typography sizes are repeated literals rather than a complete token scale. Global element and pattern styles cover controls, buttons, alerts, cards, tables, and responsive layouts (`apps/web/src/style.css:1-34,54-67,97-166`). There is no installed UI/component framework in the runtime dependencies (`apps/web/package.json:17-22`).

### High-level folder structure

```text
e-Recruit/
├── apps/
│   ├── web/                    # Vue/TypeScript SPA, unit tests, Playwright E2E
│   │   ├── public/             # UPS brand, icons, push handler
│   │   └── src/                # views, shared components, store, router, offline DB
│   └── api/                    # Laravel API
│       ├── app/                # controllers, requests, models, services, policies, jobs
│       ├── database/           # migrations, seeders, factories, canonical admin data
│       ├── routes/             # API and web route registration
│       └── tests/              # PHPUnit unit/feature tests
├── services/document-worker/  # FastAPI OCR/document-quality worker
├── infra/                      # nginx, backup, restore, and health scripts
├── tests/                      # OpenAPI contract and k6 load tests
├── docs/                       # architecture, security, operations, guides, reports
└── Resources/                  # source specification, prompts, logo, source datasets
```

This structure is directly represented by the root directories and by the application entry points `apps/web/src/main.ts:1-15`, `apps/api/routes/api.php:1-30`, and `services/document-worker/pyproject.toml:1-18`.

### Route/page inventory

There is one SPA, not separate applicant/staff/admin frontend bundles. Route metadata and the global guard determine the frontend audience; the API remains the authority (`apps/web/src/router.ts:4-45`).

| Audience | Route | Page |
| --- | --- | --- |
| Public | `/` | Opportunities/home: `apps/web/src/views/HomeView.vue` (`apps/web/src/router.ts:7`) |
| Public | `/access` | Sign-in, registration, MFA enrolment/confirmation, password replacement: `apps/web/src/views/AccessView.vue` (`apps/web/src/router.ts:8`) |
| Applicant/shared staff | `/dashboard` | Application list or scoped recruitment queue; technical administrators are redirected elsewhere: `apps/web/src/views/DashboardView.vue` (`apps/web/src/router.ts:9,39`) |
| Applicant | `/applications/:id` | Dynamic application workspace: `apps/web/src/views/ApplicationWorkspaceView.vue` (`apps/web/src/router.ts:10`) |
| Authenticated/shared | `/applications/:id/status` | Applicant record, inbox, acknowledgement, and activity timeline: `apps/web/src/views/ApplicationStatusView.vue` (`apps/web/src/router.ts:11`) |
| Staff | `/staff/verification/:id` | Evidence verification workbench: `apps/web/src/views/VerificationWorkbenchView.vue` (`apps/web/src/router.ts:12`) |
| Staff/config admin | `/staff/campaigns` | Campaign/post configuration: `apps/web/src/views/CampaignConfigurationView.vue` (`apps/web/src/router.ts:13`) |
| Staff/config admin | `/staff/geography` | Administrative units, regions, and reference imports: `apps/web/src/views/GeographyConfigurationView.vue` (`apps/web/src/router.ts:14`) |
| Staff | `/staff/assessments` | Written-score import: `apps/web/src/views/AssessmentImportView.vue` (`apps/web/src/router.ts:15`) |
| Staff/config admin | `/staff/governance` | Retention, holds, and purge controls: `apps/web/src/views/GovernanceView.vue` (`apps/web/src/router.ts:16`) |
| Staff | `/staff/selection` | Ranking/selection console: `apps/web/src/views/SelectionConsoleView.vue` (`apps/web/src/router.ts:17`) |
| Staff | `/staff/operations` | Hard-copy, interview, medical, final-selection, and training workflows: `apps/web/src/views/OperationsWorkflowView.vue` (`apps/web/src/router.ts:18`) |
| Technical admin | `/staff/users` | User/administrator management: `apps/web/src/views/UserAdministrationView.vue` (`apps/web/src/router.ts:19`) |
| Field staff | `/field/offline` | Encrypted offline field workspace: `apps/web/src/views/OfflineWorkspaceView.vue` (`apps/web/src/router.ts:20`) |
| Authenticated/shared | `/help` | Helpdesk request and request list: `apps/web/src/views/HelpdeskView.vue` (`apps/web/src/router.ts:21`) |
| Fallback | `/:pathMatch(.*)*` | Redirects to `/` (`apps/web/src/router.ts:22`) |

## 2. Existing shared UI components

### Vue components

| Component | Current capability |
| --- | --- |
| `apps/web/src/components/AppShell.vue` | Slot-based site shell with UPS logo, role-conditioned links, hamburger state, offline banner, sign-out, and footer (`:1-16,19-50`). |
| `apps/web/src/components/OfflineBanner.vue` | No props; watches browser `online`/`offline` events and renders a `role="status"` banner when disconnected (`:1-13`). |
| `apps/web/src/components/StatusBadge.vue` | One required `status: string` prop; converts underscores to spaces and exposes a normalized `data-status` value for CSS coloring (`:1-7`). |
| `apps/web/src/components/AdministrativeAddressSelector.vue` | Controlled `modelValue` plus update event; four cascading native selects and a keyboard-operable village combobox that can fill the complete lineage (`:19-22,59-72,74-80,228-273`). |
| `apps/web/src/components/EducationRecordsForm.vue` | `v-model` array of education records; add/remove, grouped level selection, level-specific result selection, and institution-directory composition (`:12-49,56-99`). It is form-like but does not itself render a semantic `<form>`. |
| `apps/web/src/components/InstitutionDirectoryField.vue` | `v-model` education record; debounced searchable official-directory combobox, seven-result cap, keyboard navigation, provenance capture, and checkbox-controlled manual fallback (`:19-36,55-173,180-244`). |
| `apps/web/src/components/HelloWorld.vue` | Unused Vite starter component; no import exists under `apps/web/src`. It is not part of the product UI. |

### CSS-only shared patterns

- Buttons are repeated native `<button>`/links using `.button` plus `.primary`, `.secondary`, `.compact`, and `.full`; `.text-button.danger` is a second pattern (`apps/web/src/style.css:59-66,188-189`). There is no shared Button component.
- Inputs, selects, textareas, labels, field grids, and `.form-panel` are global CSS patterns (`apps/web/src/style.css:120-150`). There are no shared Input or Select components.
- Alerts use `.alert.error` and `.alert.success` (`apps/web/src/style.css:108-111`); semantics are added inconsistently per view. There is no toast component/library.
- Cards are CSS classes (`.campaign-card`, `.run-card`, `.ticket-card`, metric `<article>` elements), not components (`apps/web/src/style.css:79-87,154-157,230-236`).
- Tabs exist only as inline markup in AccessView (`apps/web/src/views/AccessView.vue:95`) and styles (`apps/web/src/style.css:122-124`).
- The application wizard and applicant timeline are page-local markup, not shared stepper/timeline components (`apps/web/src/views/ApplicationWorkspaceView.vue:128-137`; `apps/web/src/views/ApplicationStatusView.vue:59`).
- Loading uses page-local text/progress states; there is no spinner or skeleton primitive (`apps/web/src/views/HomeView.vue:52-53`; `apps/web/src/views/DashboardView.vue:29`; `apps/web/src/views/VerificationWorkbenchView.vue:88`; `apps/web/src/views/ApplicationWorkspaceView.vue:135`).

No Radix, Headless UI, Material UI, Bootstrap, Vuetify, PrimeVue, Element Plus, or equivalent component dependency is installed (`apps/web/package.json:17-46`).

## 3. Forms inventory

### Common form and validation pattern

All frontend forms use Vue `ref`/`reactive` plus `v-model` and `@submit.prevent`; there is no form library such as VeeValidate, FormKit, or Yup/Zod (`apps/web/package.json:17-46`). Client checks are browser attributes (`required`, `type`, `min`, `minlength`, `pattern`) plus occasional hand-written computed/functions. The fetch wrapper turns non-2xx JSON into `ApiError`, retaining Laravel's `errors` map (`apps/web/src/lib/api.ts:1-16,73-87`). Most pages show one flattened message in a top banner rather than field-level errors.

AccessView is the exception that stores the server error map and renders selected inline `<small>` messages for identity/current password/new password, while also showing the overall message (`apps/web/src/views/AccessView.vue:28-32,96-111`). Application submission and user administration flatten all server field errors into one page alert (`apps/web/src/views/ApplicationWorkspaceView.vue:109-121,127`; `apps/web/src/views/UserAdministrationView.vue:44-57,172`).

### Page-by-page forms

| Route/page | Forms and current pattern | Validation/state reporting | Modal-conversion target? |
| --- | --- | --- | --- |
| `/access` — `AccessView.vue` | Four mutually exclusive forms: sign in, applicant registration, TOTP confirmation, and password replacement; MFA enrolment password controls are in a `<div>`, not a form (`apps/web/src/views/AccessView.vue:97-111`). | Native constraints plus Laravel errors; limited inline field errors and one `role="alert"` banner (`:28-32,96-111`). | No simultaneous forms. The flow is already phased, although enrolment markup is semantically inconsistent. |
| `/applications/:id` — `ApplicationWorkspaceView.vue` | Conditional personal, address/origin/residence, declaration, and upload forms; education is a form-like child component and review is a button-driven panel (`apps/web/src/views/ApplicationWorkspaceView.vue:128-137`; `apps/web/src/components/EducationRecordsForm.vue:56-99`). A deep watcher autosaves after 900 ms (`apps/web/src/views/ApplicationWorkspaceView.vue:58-75`). | Native constraints; completion is truthiness-based, save state is `saved/saving/offline/conflict`, and final server errors are flattened into the top alert (`:36-41,63-75,109-127`). | No: sections are mutually exclusive wizard steps. |
| `/staff/campaigns` — `CampaignConfigurationView.vue` | One large new-campaign form beside the campaign register (`apps/web/src/views/CampaignConfigurationView.vue:38`). | Native constraints plus a custom computed guardrail list for dates, reference prefix, and JSON validity (`:8-15,38`); success/error banners (`:30-33,37`). | **Yes:** creation is a utility form beside the primary register. |
| `/staff/geography` — `GeographyConfigurationView.vue` | Add/edit administrative unit, transactional import controls, add prison region, and hierarchy filters/list all share one page (`apps/web/src/views/GeographyConfigurationView.vue:118-123`). Import controls use a `.form-panel` `<div>`, not a form. | Native constraints; server banner; import row errors get a table (`:95-106,117-123`). Delete alone uses `window.confirm` (`:90-93`). | **Yes:** multiple inline admin forms and utility controls are the clearest Task-1 target. |
| `/staff/assessments` — `AssessmentImportView.vue` | Written-assessment/file import form beside import register (`apps/web/src/views/AssessmentImportView.vue:50-54`). | Native constraints; general banners; structured per-row server validation table (`:25-35,52-53`). | **Yes:** utility form beside primary register. |
| `/staff/governance` — `GovernanceView.vue` | Retention-policy, legal-hold, and purge-request forms are simultaneously inline (`apps/web/src/views/GovernanceView.vue:29-32`). Decision and execute actions are table buttons. | Native constraints and top success/error banners only (`:15-24,30-32`). | **Yes:** three high-consequence action forms on one view. |
| `/staff/selection` — `SelectionConsoleView.vue` | Selection-scenario form beside immutable run register; certification is an inline row button (`apps/web/src/views/SelectionConsoleView.vue:23-26`). | Native number constraints; JSON is parsed at submission with errors reduced to one banner (`:13-20`). | **Yes:** scenario builder is secondary to the run register. |
| `/staff/operations` — `OperationsWorkflowView.vue` | Eleven forms are simultaneously rendered: hard-copy receipt, scheduling, attendance, panel closure, medical schedule, medical result, final approval, training invite, training report, reserve recommendation, and replacement decision (`apps/web/src/views/OperationsWorkflowView.vue:112-140`). | Native constraints; hand-written JSON-array parsing; one shared success/error banner (`:21-45,109-140`). | **Yes — highest-density target:** eleven unrelated action forms compete on one page. |
| `/staff/users` — `UserAdministrationView.vue` | Create account and directory filter forms are always visible; selecting a user adds identity/access and scopes forms plus a security-actions panel (`apps/web/src/views/UserAdministrationView.vue:175-194`). | Native constraints; server errors flattened; shared `reason` state is reused across edit, scopes, and security actions (`:33-38,44-57,82-97`). | **Yes:** create/edit/scopes/security operations should be separated from the directory/list context. |
| `/field/offline` — `OfflineWorkspaceView.vue` | Configure-PIN or unlock-PIN form is conditionally shown. After unlock, bind/provision, capture, sync, and conflict controls are form-like panels without semantic `<form>` wrappers (`apps/web/src/views/OfflineWorkspaceView.vue:230-285`). | Native PIN pattern plus hand-written equality, entity, JSON, online, and reason checks; one shared success/error banner (`:77-91,119-218,227-285`). | Potentially: not multiple semantic forms at once, but several secondary workflows share the unlocked view. |
| `/help` — `HelpdeskView.vue` | New support-request form beside the request list (`apps/web/src/views/HelpdeskView.vue:13-15`). | Native constraints and top success/error banners (`:7-10,14`). | **Yes:** creation is a utility form beside the request register. |
| `/staff/verification/:id` — `VerificationWorkbenchView.vue` | Field/action/outcome/value/reason controls and submit button are in a `.decision-panel`, but not a semantic `<form>` (`apps/web/src/views/VerificationWorkbenchView.vue:93-96`). | No browser-required constraints on the decision controls; API failure/success appears in top banners (`:38-54,82`). | Potentially: decision entry is secondary to the evidence matrix; modal use must preserve simultaneous access to source evidence. |

`HomeView.vue`, `DashboardView.vue`, and `ApplicationStatusView.vue` contain action buttons but no data-entry `<form>` (`apps/web/src/views/HomeView.vue:45-72`; `apps/web/src/views/DashboardView.vue:26-41`; `apps/web/src/views/ApplicationStatusView.vue:55-62`).

## 4. Select/dropdown inventory

There are 37 native `<select>` elements and two custom ARIA comboboxes in the current frontend. Native selects are globally enhanced after app mount (`apps/web/src/main.ts:6-9`). Any single select with more than seven options has `size=7` applied and therefore expands inside its own control/layout box as a scrollable listbox; change, focus-out, or Escape removes `size` (`apps/web/src/lib/limitedSelectViewport.ts:1-26,33-79`; `apps/web/src/style.css:129-130`). Selects with seven or fewer options retain the browser's native popup behavior.

| Location | Controls and approximate list size | Current behavior |
| --- | --- | --- |
| `AdministrativeAddressSelector.vue` | District/city: 146 current active records; county: up to about 8 per district; sub-county/town/division: up to about 21 per county; parish/ward: up to about 48 per sub-county. Each API load is capped at 250 (`apps/web/src/components/AdministrativeAddressSelector.vue:24-28,59-65,228-242`; `apps/api/app/Http/Requests/SearchAdministrativeUnitsRequest.php:26-37`). | Four native cascading selects. Lists over seven use the **inline-expanded seven-row** behavior. The component is used for the campaign-configured address/origin/residence wizard sections (`apps/web/src/views/ApplicationWorkspaceView.vue:31-35,132`). |
| `AdministrativeAddressSelector.vue` | Village/cell search: at most 25 returned options, searchable nationally after two characters (`apps/web/src/components/AdministrativeAddressSelector.vue:163-187,243-268`). | Custom input + ARIA listbox. This is the only current **true floating dropdown**: its result list is absolutely positioned (`apps/web/src/style.css:137-142`). Selecting a village fills all higher units (`apps/web/src/components/AdministrativeAddressSelector.vue:190-206`). |
| `EducationRecordsForm.vue` | Level: 32 configured choices plus blank/history fallback; result/class: 2–11 choices depending on level plus blank/history fallback (`apps/web/src/lib/educationQualifications.ts:22-100,102-310`; `apps/web/src/components/EducationRecordsForm.vue:72-92`). | Native selects. Level always uses the **inline-expanded seven-row** behavior. UCE and some TVET result lists exceed seven and expand inline; shorter result lists retain native popup behavior. |
| `InstitutionDirectoryField.vue` | Institution: server and client both cap matches at seven (`apps/web/src/components/InstitutionDirectoryField.vue:69-107,180-220`; `apps/api/config/erecruit.php:36-45`). | Custom ARIA combobox. The results container is not positioned, so it appears below the input in normal flow and pushes later content; it is not a floating overlay (`apps/web/src/style.css:178-186`). Manual input appears only after the checkbox is selected (`apps/web/src/components/InstitutionDirectoryField.vue:228-242`). |
| `AccessView.vue` | Registration sex: blank + Female/Male/Other (4) (`apps/web/src/views/AccessView.vue:104-107`). | Short native popup. |
| `ApplicationWorkspaceView.vue` | Upload document type: 4 (`apps/web/src/views/ApplicationWorkspaceView.vue:135`). | Short native popup. |
| `AssessmentImportView.vue` | Written assessment: dynamic API list; the seed supplies one oral-interview definition, but campaigns can add more (`apps/web/src/views/AssessmentImportView.vue:14-23,53`; `apps/api/database/seeders/DatabaseSeeder.php:172-191`). | Native popup until the list exceeds seven, then inline-expanded. |
| `GeographyConfigurationView.vue` | Unit level: 7; detailed type: 1–3; parent: dynamic up to the API page of 100 plus placeholder; import dataset: 2; hierarchy level filter: All + 7 levels = 8 (`apps/web/src/views/GeographyConfigurationView.vue:13,25-33,57-70,119-123`; `apps/api/app/Http/Controllers/Api/V1/GeographyController.php:71-87`). | Native. Parent and the 8-option filter can be **inline-expanded**; other controls remain native popups. |
| `GovernanceView.vue` | Policy and purge record category: 3 server-defined categories each (`apps/web/src/views/GovernanceView.vue:31-32`; `apps/api/app/Http/Controllers/Api/V1/GovernanceController.php:15-28`). | Short native popups. |
| `HelpdeskView.vue` | Ticket category: 6 (`apps/web/src/views/HelpdeskView.vue:14`). | Short native popup. |
| `OfflineWorkspaceView.vue` | Pack purpose: 6; pack record: dynamic scoped pack size; attendance status: 7; verification action: 7; verification outcome: 5; medical outcome: 5 (`apps/web/src/views/OfflineWorkspaceView.vue:8-21,255-275`). | Native. Only a pack-record list with more than seven expands inline; fixed lists remain native popups. |
| `OperationsWorkflowView.vue` | Attendance status: 7; medical outcome: 5; training status: 11; reserve trigger: 4; replacement decision: 2 (`apps/web/src/views/OperationsWorkflowView.vue:121,129,137-139`). | Native. Training status uses the **inline-expanded seven-row** behavior; the others remain native popups. |
| `SelectionConsoleView.vue` | Scenario mode: 2 (`apps/web/src/views/SelectionConsoleView.vue:25`). | Short native popup. Skill reservations and quota buckets are JSON textareas, not selects. |
| `UserAdministrationView.vue` | Initial/edit role: 18 staff roles in the current seed; role filter: All + 19 roles; filter status: 3; edit status: 2 (`apps/web/src/views/UserAdministrationView.vue:38,59-79,178-193`; `apps/api/database/seeders/DatabaseSeeder.php:23-45`). | Role selects use the **inline-expanded seven-row** behavior; status selects retain native popup behavior. |
| `VerificationWorkbenchView.vue` | Evidence field: dynamic matrix keys; action: 7; outcome: 5 (`apps/web/src/views/VerificationWorkbenchView.vue:21-22,94-96`). | Native. Evidence field expands inline only if a record has more than seven keys; action/outcome remain native popups. |

The current imported administrative source has about 84,980 nodes (`apps/api/database/data/uganda_admin_units_nodes.csv`; provenance is documented at `apps/api/database/data/README.md:1-20`). The audit-time database split was 146 districts/cities, 322 counties/municipalities, 2,191 sub-counties/towns/divisions, 10,717 parishes/wards, and 71,230 villages/cells, which explains why type-ahead is mandatory at village level.

No skill-category select, region filter, panel filter, or generic status filter exists outside the controls listed above. Seeded skill categories exist, but selection uses a JSON textarea (`apps/api/database/seeders/DatabaseSeeder.php:192-195`; `apps/web/src/views/SelectionConsoleView.vue:25`). Operational panel/application/centre references are mostly raw IDs or comma-separated textareas (`apps/web/src/views/OperationsWorkflowView.vue:114-139`).

## 5. Modal/dialog inventory

No shared modal/dialog component exists. No `<dialog>`, Vue `<Teleport>`, `role="dialog"`, `aria-modal`, focus-trap utility, modal animation, Escape handler, or backdrop-click implementation is present under `apps/web/src`. No installed component library supplies these primitives (`apps/web/package.json:17-46`).

The only modal-like interaction is the browser's native deletion confirmation in geography administration (`apps/web/src/views/GeographyConfigurationView.vue:90-93`). Its focus handling, Escape behavior, and backdrop are browser-owned and cannot be reused as application modal infrastructure.

| Multi-form/utility page | Infrastructure available today |
| --- | --- |
| Campaigns, assessment imports, selection, and helpdesk | No modal infrastructure; each form is directly paired with its register (`apps/web/src/views/CampaignConfigurationView.vue:38`; `AssessmentImportView.vue:53`; `SelectionConsoleView.vue:25`; `HelpdeskView.vue:14`). |
| Geography | No application modal; only `window.confirm` for deletion (`apps/web/src/views/GeographyConfigurationView.vue:90-123`). |
| Governance | No modal; all three high-consequence forms are inline (`apps/web/src/views/GovernanceView.vue:31-32`). |
| Operations | No modal; all eleven forms are inline (`apps/web/src/views/OperationsWorkflowView.vue:112-140`). |
| User administration | No modal; create, filter, edit, scopes, and security controls are inline (`apps/web/src/views/UserAdministrationView.vue:175-194`). |
| Offline and verification workspaces | No modal; utility controls are page-local panels (`apps/web/src/views/OfflineWorkspaceView.vue:247-285`; `apps/web/src/views/VerificationWorkbenchView.vue:93-96`). |

Task 1 is therefore greenfield: an accessible dialog primitive should be built and tested before converting individual forms.

## 6. Validation, toasts, and status/state reporting

### Success, error, and validation feedback

- There is no toast library or global notification store. Pages generally keep `message`/`notice` and `error` refs and render `.alert.success`/`.alert.error` blocks (`apps/web/src/style.css:108-111`; examples: `apps/web/src/views/OperationsWorkflowView.vue:5-7,109-110` and `apps/web/src/views/UserAdministrationView.vue:28-31,172-173`).
- ARIA live semantics are inconsistent. User administration, geography, and operations mark success/error with `role="status"`/`role="alert"`, while campaign, governance, assessment, selection, helpdesk, verification, and applicant status use visually identical banners without those roles (`apps/web/src/views/UserAdministrationView.vue:172-173`; `CampaignConfigurationView.vue:37`; `GovernanceView.vue:30`; `AssessmentImportView.vue:52`; `SelectionConsoleView.vue:24`; `HelpdeskView.vue:14`; `VerificationWorkbenchView.vue:82`; `ApplicationStatusView.vue:57-61`).
- Buttons usually communicate busy state by changing their text and/or `disabled`; there is no shared loader (`apps/web/src/views/AccessView.vue:102-111`; `apps/web/src/views/ApplicationWorkspaceView.vue:135-136`; `apps/web/src/views/OperationsWorkflowView.vue:114-139`).
- Structured validation survives only in a few places: AccessView renders three selected inline errors, score/geography imports render row tables, and other pages flatten or discard field association (`apps/web/src/views/AccessView.vue:28-32,96-111`; `AssessmentImportView.vue:25-35,53`; `GeographyConfigurationView.vue:95-106,120`; `UserAdministrationView.vue:44-47`).
- Positive local states use dedicated but page-specific blocks: selected administrative address, selected institution, applicant save status/progress, temporary password, and offline event states (`apps/web/src/style.css:101-107,143-146,186,244-246`; `apps/web/src/views/OfflineWorkspaceView.vue:251-285`).

### Applicant application status

`ApplicationStatusView.vue` renders the current `StatusBadge`, secure inbox, and an `<ol class="timeline">` of actual status-history events (`apps/web/src/views/ApplicationStatusView.vue:55-61`). CSS draws a vertical dot/line timeline (`apps/web/src/style.css:198-206`). The API serializes only recorded transitions with status, reason, and timestamp (`apps/api/app/Http/Resources/ApplicationResource.php:50-54`; ordering is defined in `apps/api/app/Models/Application.php:44-47`).

This is not a full stage stepper showing Submitted → Validated → Shortlisted → Interviewed → Medical → Final List → Training with completed/current/future states. Campaign stages do include application, hard copy, verification, eligibility, interview, selection, medical, and training (`apps/web/src/views/CampaignConfigurationView.vue:25-29`; seeded defaults at `apps/api/database/seeders/DatabaseSeeder.php:59-67`), but the status page does not combine those definitions with history.

### Dashboard/admin state metrics

`DashboardView.vue` renders three raw metric `<article>` tiles: total applications, open sync conflicts, and unsynced packs, then an application queue table (`apps/web/src/views/DashboardView.vue:26-38`; tile styles at `apps/web/src/style.css:154-157`). The API also returns application funnel, sex distribution, document processing, and eligibility aggregates, but the current page does not render them (`apps/api/app/Http/Controllers/Api/V1/ReportController.php:16-46`).

No current frontend tile/chart reports validation backlog, interview progress, quota/capping status, medical progress, final-list readiness, or training intake. There is no chart dependency in `apps/web/package.json:17-46`.

## 7. Mobile/responsive state

- Responsive behavior is global CSS at 980 px and 680 px (`apps/web/src/style.css:250-285`). At 980 px the primary nav becomes an absolutely positioned hamburger menu, multi-column admin/workbench layouts become one column, and the wizard navigation becomes horizontally scrollable (`:250-265`; trigger/ARIA state in `apps/web/src/components/AppShell.vue:9,27-44`). There is no bottom navigation or mobile-specific route shell.
- At 680 px grids collapse, hero artwork is hidden, upload rows stack, and large panels reduce padding (`apps/web/src/style.css:268-285`). Tables retain horizontal scrolling through `.table-wrap` rather than becoming native-style list rows (`apps/web/src/style.css:158-162`).
- Buttons have a 46 px minimum height and tap highlight is disabled, but there are no safe-area insets, dynamic viewport units, bottom sheets, swipe gestures, or mobile view-transition primitives (`apps/web/src/style.css:24-26,59-66`).
- A PWA is present. `vite-plugin-pwa` generates a standalone manifest, precaches brand/icons, adds navigation fallback, imports the push handler, and network-first caches public campaigns (`apps/web/vite.config.ts:1-34`). Registration occurs after window load (`apps/web/src/main.ts:11-16`); push/click handling is in `apps/web/public/push-handler.js:1-19`.
- Offline data uses Dexie/IndexedDB and Web Crypto for drafts, scoped packages, event outbox, and PIN-wrapped keys (`apps/web/src/offline/database.ts:1-65,73-132`). A global offline banner exists (`apps/web/src/components/OfflineBanner.vue:1-13`), and the field workspace provides scoped synchronization (`apps/web/src/views/OfflineWorkspaceView.vue:23-65,147-218`).
- No animation library is installed. The only UI motion is a CSS button hover transition and smooth scrolling, both disabled under `prefers-reduced-motion` (`apps/web/src/style.css:23,59-61,287-289`).

## 8. Auth & 2FA implementation

### Authentication and authorization

The SPA posts credentials to Laravel and stores the returned Sanctum bearer token in `localStorage` (`apps/web/src/stores/session.ts:28-36`; `apps/web/src/lib/api.ts:42-59`). Tokens are database-backed Sanctum personal-access tokens. Normal tokens expire after 12 hours; MFA-enrolment and required-password-change tokens expire after 15 minutes and have limited abilities (`apps/api/app/Http/Controllers/Api/V1/AuthController.php:89-98,114-137,140-167`). API routes use `auth:sanctum`, password-change, MFA, and role middleware (`apps/api/routes/api.php:40-46,77-106`; `apps/api/app/Http/Middleware/RequireMfa.php:16-24`; `RequirePasswordChange.php:16-23`).

The Vue router provides convenience redirects only; frontend role metadata is not the security boundary (`apps/web/src/router.ts:33-45`).

### TOTP/MFA

TOTP is implemented in-house in `TotpService`, not through `pyotp`, `otplib`, or Google2FA. It generates a 20-byte Base32 secret, verifies six-digit SHA-1 TOTP values in a ±1 30-second window, produces `otpauth://` URIs, and creates eight hashed recovery codes (`apps/api/app/Services/TotpService.php:7-71,74-120`).

For privileged users, login issues a limited enrolment token when no secret exists; otherwise it requires TOTP or a recovery code (`apps/api/app/Http/Controllers/Api/V1/AuthController.php:77-112`). The secret and recovery-code hashes are saved during enrolment and the secret is marked confirmed only after a valid TOTP (`apps/api/app/Http/Controllers/Api/V1/AuthController.php:184-226`). The model encrypts the secret and encrypted array of recovery hashes at rest (`apps/api/app/Models/User.php:17-18,55-67`).

The current frontend is **text-only**, not QR-based: it prints the full provisioning URI in a `<code>` element and lists recovery codes (`apps/web/src/views/AccessView.vue:53-68,109-110`). Although the frontend has `qrcode` 1.5.4 installed and the backend has Endroid QR Code 6.1.3, neither is used by MFA enrolment (`apps/web/package.json:17-22`; `apps/api/composer.lock:1075-1084`). Endroid is already used for official PDF artifact QR codes (`apps/api/app/Services/InvitationArtifactService.php:6-32`).

The API accepts a recovery code during login, but the frontend exposes only the authenticator-code input and never sends `recovery_code` (`apps/api/app/Http/Requests/LoginRequest.php:23-31`; `apps/api/app/Http/Controllers/Api/V1/AuthController.php:101-110`; `apps/web/src/stores/session.ts:28-32`; `apps/web/src/views/AccessView.vue:97-102`).

### Email and email OTP readiness

Laravel mail infrastructure exists. Development uses Mailpit over SMTP (`docker-compose.yml:75-85,133-135`); production examples expect an approved internal SMTP host and secret-injected credentials, without naming a vendor (`.env.production.example:44-50`). Laravel's mail config supports SMTP and other transports (`apps/api/config/mail.php:17-100`). The queued `DeliverNotificationJob` sends generic secure-portal update emails with retry/attempt evidence (`apps/api/app/Jobs/DeliverNotificationJob.php:16-25,34-105,118-126`).

No email-OTP route, service, challenge persistence, expiry/attempt model, UI, or tests exist. A rate limiter named `otp` is registered (`apps/api/app/Providers/AppServiceProvider.php:52`), but the two MFA routes are not currently assigned that limiter (`apps/api/routes/api.php:43-44`). Email OTP therefore needs new authentication-specific plumbing even though SMTP delivery is available.

## 9. Testing & CI

### Test frameworks and relevant coverage

| Layer | Framework and coverage |
| --- | --- |
| Frontend unit/component | Vitest 4.1.11, jsdom, Testing Library Vue, and Vue Test Utils (`apps/web/package.json:24-46`; `apps/web/vite.config.ts:42-51`). Education select behavior is covered in `apps/web/src/components/EducationRecordsForm.spec.ts:7-66`; administrative cascading/village fill in `AdministrativeAddressSelector.spec.ts:17-62`; institution search/manual fallback in `InstitutionDirectoryField.spec.ts:37-105`; seven-row native-select enhancement in `apps/web/src/lib/limitedSelectViewport.spec.ts:17-53`; status badges in `apps/web/src/components/StatusBadge.spec.ts:5-9`. |
| Browser E2E/accessibility | Playwright 1.62.1 plus Axe; one desktop and one mobile Chromium project (`apps/web/playwright.config.ts:1-16`). Sixteen scenarios cover public/access responsiveness, password replacement, technical administration, the applicant wizard/upload/status timeline, verification, operations, selection, medical/training, and offline conflicts (`apps/web/e2e/portal.spec.ts:10-435`). |
| Backend | PHPUnit 12.5.34 (`apps/api/composer.lock:8979-8984`). Auth registration/login is covered in `apps/api/tests/Feature/AuthenticationTest.php:12-39`; privileged MFA/password sequencing and MFA reset in `TechnicalUserAdministrationTest.php:51-131,186-214`; address CRUD/search in `AdministrativeAddressTest.php:20-151`; education directories in `EducationInstitutionDirectoryTest.php:20-246`; workflow services and controllers have focused unit/feature files under `apps/api/tests/Unit/Domain` and `apps/api/tests/Feature`. |
| Document worker | pytest plus Ruff configuration (`services/document-worker/pyproject.toml:20-34`); health/auth contract and processor behavior in `services/document-worker/tests/test_health.py:10-34` and `test_processor.py:25-49`. |
| Contract/load | Node's built-in test runner validates the OpenAPI boundary (`tests/contract/openapi.test.mjs:1-35`). k6 defines public and authenticated smoke scenarios and latency/error thresholds (`tests/load/k6-smoke.js:1-50`). |

There are no modal tests because no modal exists. There is backend coverage of the TOTP enrol/confirm sequence, but no frontend browser scenario actually performs MFA enrolment or verifies QR/recovery-code UI (`apps/api/tests/Feature/TechnicalUserAdministrationTest.php:83-131`; MFA is absent from `apps/web/e2e/portal.spec.ts`).

### CI

`.github/workflows/ci.yml` runs on pushes and pull requests. It executes:

- OpenAPI contract tests (`.github/workflows/ci.yml:11-18`).
- Composer install/validation, Pint, PHPUnit, and Composer audit on PHP 8.5 (`:20-36`).
- npm clean install, lint, typecheck, Vitest, production build, Playwright Chromium E2E, and npm audit (`:38-62`).
- Python dependency install, Ruff, and pytest for the document worker (`:64-78`).
- Docker Compose configuration validation (`:80-85`).

## 10. Risks & open questions for the improvement pass

1. **Accessible modals are greenfield.** There is no reusable dialog, focus trap, portal/Teleport layer, scroll lock, nested-dialog policy, Escape behavior, or modal test harness (`apps/web/package.json:17-46`; no dialog implementation under `apps/web/src`). Build the primitive first and test focus return, initial focus, Tab containment, Escape, backdrop policy, ARIA labeling, and mobile sizing before converting forms.

2. **Task 1 varies by risk, not just layout.** Operations has eleven simultaneous forms (`apps/web/src/views/OperationsWorkflowView.vue:112-140`), geography mixes CRUD/import/filter/delete (`GeographyConfigurationView.vue:118-123`), and governance has independently authorized destructive stages (`GovernanceView.vue:31-32`). A generic “open form in modal” conversion must preserve server role checks, reasons, confirmation gates, selected record context, busy state, and one-time secrets.

3. **The current seven-option solution deliberately mutates native controls into inline listboxes.** `mousedown` is cancelled and `size=7` is added globally (`apps/web/src/lib/limitedSelectViewport.ts:9-26,33-72`). Replacing it requires removing that enhancer and covering pointer, keyboard, screen reader, touch, viewport collision, and form submission behavior for dynamically rendered controls.

4. **There are already two incompatible custom combobox patterns.** Village results are a positioned overlay with up to 25 results (`apps/web/src/components/AdministrativeAddressSelector.vue:163-220,243-268`; `apps/web/src/style.css:137-142`); institution results are capped at seven but remain in normal flow (`apps/web/src/components/InstitutionDirectoryField.vue:69-107,180-220`; `apps/web/src/style.css:178-186`). A shared floating-select primitive needs asynchronous loading, debounce/cancellation, active descendant, no-results/error states, selected provenance, and full-lineage autofill.

5. **Large option domains require server search rather than client rendering.** The canonical administrative data has roughly 85,000 rows (`apps/api/database/data/README.md:1-20`; `uganda_admin_units_nodes.csv`), and the institution table is populated from large official directories (`apps/api/config/erecruit.php:36-65`). District can remain bounded, but village and institution controls must keep type-ahead and request limits.

6. **Frontend/backend education level lists are inconsistent in the current tree.** The frontend exposes 32 levels (`apps/web/src/lib/educationQualifications.ts:102-310`), while the API institution-search allow-list contains only 25 and stops at `Craft Certificate (legacy TVET)` (`apps/api/config/erecruit.php:46-65`; request enforcement at `apps/api/app/Http/Requests/SearchEducationInstitutionsRequest.php:24-30`). The seven omitted frontend levels will produce a 422 directory-search response and force manual fallback. Resolve the catalogue ownership before rebuilding the dropdown.

7. **Validation is fragmented and mostly banner-level.** The API preserves structured Laravel errors (`apps/web/src/lib/api.ts:73-87`), but most pages flatten them and several form-like panels are not semantic forms (`apps/web/src/views/UserAdministrationView.vue:44-47`; `VerificationWorkbenchView.vue:93-96`; `OfflineWorkspaceView.vue:247-285`). Task 3 needs a shared field/error contract, form summary behavior, consistent live regions, focus-to-first-error, and preservation of row-level import reports.

8. **Status UI does not yet model future stages.** The applicant timeline shows historical events only (`apps/web/src/views/ApplicationStatusView.vue:59`; `apps/api/app/Http/Resources/ApplicationResource.php:50-54`), and the dashboard renders only three of the available aggregates (`apps/web/src/views/DashboardView.vue:31-38`; `apps/api/app/Http/Controllers/Api/V1/ReportController.php:34-46`). Product owners must define canonical stage labels, skipped/failed/referred states, and what each role may see before a modern stepper/dashboard is designed.

9. **Mobile behavior is responsive web, not native-app navigation.** The current small-screen shell is a hamburger overlay; the wizard uses a horizontal scroller and tables use horizontal overflow (`apps/web/src/style.css:250-285`; `apps/web/src/components/AppShell.vue:27-44`). Task 4 needs explicit decisions about bottom navigation by role, back behavior, safe areas, offline indicators, table-to-card transformations, and whether admin workflows should remain desktop-first.

10. **MFA enrolment has useful QR dependencies but a risky partial-enrolment state.** The secret and recovery hashes are persisted before confirmation (`apps/api/app/Http/Controllers/Api/V1/AuthController.php:184-201`); a user who abandons the flow can return with an unconfirmed but non-null secret, causing subsequent login to demand a TOTP (`:89-112`). The QR/email alternative design should define pending challenge expiry, restart/recovery, secret rotation, audit events, and when recovery codes become active.

11. **Email OTP is not a presentation-only change.** SMTP and queued generic email exist (`docker-compose.yml:75-85,133-135`; `apps/api/app/Jobs/DeliverNotificationJob.php:118-126`), but there is no challenge model, hashed code, purpose binding, expiry, resend policy, attempt lockout, failover rule, or email-OTP route/UI/test. The existing `otp` limiter is not attached to MFA routes (`apps/api/app/Providers/AppServiceProvider.php:52`; `apps/api/routes/api.php:43-44`). Decide whether email is an alternative second factor, recovery channel, or temporary fallback before implementation.

12. **Recovery-code support is backend-only.** Login validation/controller accept a recovery code, while the SPA sends only `totp_code` and exposes no recovery option (`apps/api/app/Http/Requests/LoginRequest.php:23-31`; `apps/web/src/stores/session.ts:28-32`; `apps/web/src/views/AccessView.vue:97-102`). The redesigned access flow should include this existing channel rather than making email OTP the sole recovery path.

13. **Client route visibility is coarse.** Most staff routes use only `meta.staff`, and navigation largely distinguishes applicant, staff, and technical administrator (`apps/web/src/router.ts:12-20,33-45`; `apps/web/src/components/AppShell.vue:29-40`). Individual APIs perform finer role/scope checks, so users can still reach a page and receive 403 responses. UI work should consume explicit capabilities or role-aware route metadata without weakening backend authorization.

14. **Current tests provide a base but not the new primitives.** Selects, form journeys, and auth sequencing have coverage, but there is no modal suite, no frontend MFA-enrolment E2E, no QR scan/fallback test, and no email-OTP test (`apps/web/src/lib/limitedSelectViewport.spec.ts:17-53`; `apps/web/e2e/portal.spec.ts:10-435`; `apps/api/tests/Feature/TechnicalUserAdministrationTest.php:83-131`). Each new primitive/channel needs focused unit, accessibility, desktop/mobile E2E, and backend abuse-case coverage.

## 11. Post-audit verification and operations remediation (15 September 2026)

### Task A — before and after

Before remediation, the verification workbench interpolated the full stored application draft as an object and displayed document ULIDs as source labels. That exposed the primary-key format and made personal, address, education and declaration evidence difficult to review.

The workbench now presents named personal fields, origin/residence address lineage in District → County → Subcounty → Parish → Village order, a compact education table, Yes/No declarations, formatted dates, original filenames, protected previews and human document-source labels. Internal application and document keys remain only in route parameters, component keys and API payloads. The desktop document and decision panes are an exact 50/50 split and stack at the established mobile breakpoint. A source scan and component regression test confirm that the page no longer renders the stored object, storage paths, hashes or raw IDs.

### Task D — LC1 prerequisite and allocation design

The original application model had a post-level LC-source policy, but it did not persist which of origin or residence was actually supported by an LC1 letter for `origin_or_residence` posts. This remediation therefore built the prerequisite first: the application form captures the LC1 address choice, submission resolves it against the canonical administrative address, and the application persists `routing_address_type` plus `routing_district_id`. Legacy validated records are resolved through the same service before allocation and fail with a corrective validation message when the evidence is ambiguous.

Interview allocation is now a versioned preview/commit workflow. It resolves routing districts to prison regions through effective jurisdiction mappings, selects active centres with scheduled/open sessions and panels, and deliberately uses the greedy longest-processing-time heuristic. Every district is allocated whole to the least-loaded centre that can hold it; candidates are then distributed to the least-loaded available panels. Input and result fingerprints prevent stale previews from being committed, each rerun receives a new immutable version, and committed assignments retain the causal run identifier. Commit reuses the protected invitation and notification pipeline.

Backend regression coverage proves that a district is never split and that a synthetic 8/7/6/5 candidate distribution reaches a 13/13 centre balance without asserting impossible equality for every dataset. It also covers rerun versioning, queued invitations, human lookup labels, document checklists and scope denial.

### New backend endpoints

- `GET /api/v1/operations/lookups` — role/scope-filtered human labels for posts, regions, centre sessions, interview assignments, panels and downstream medical/selection/training registers, plus the server-authoritative headquarters receipt capability and location.
- `GET /api/v1/operations/applications?search=…&context=…` — rate-limited, seven-result search by applicant name, NIN or applicant-facing reference.
- `GET /api/v1/interview-allocation-runs` — recent immutable allocation versions.
- `POST /api/v1/interview-allocation-runs/preview` — store a new district-balanced preview and fingerprints.
- `POST /api/v1/interview-allocation-runs/{allocationRun}/commit` — commit an unchanged preview, persist assignments and queue invitations.

### Operations form audit result

All eleven actions on the Operations page now use the shared dialog, floating combobox, validation alert and toast primitives. Application, post, region, assignment, panel, schedule, selection, medical, training and replacement relationships are chosen using human-readable, scoped registers; the underlying IDs are silent submitted values. Hard-copy receipt is restricted to the headquarters clerk capability and its receiving point is fixed by the server rather than selected from interview centres. Hard-copy documents are checkboxes, training instructions are repeatable fields, and bounded statuses are select/combobox controls. No JSON textarea or raw-ID/hash paste field remains on either the Operations or Verification page, and neither page renders a raw internal ID as user-facing text.

### App-wide human-reference remediation

The same control rule was applied beyond Operations and Verification. Written-score imports now select a named centre session; campaign eligibility and selection configuration use bounded numeric/select controls; selection policies use searchable ranking runs plus repeatable quota, skill-reservation and tie-break rows; helpdesk requests select named campaigns and applicant-facing application references; and technical administrators assign scopes through named jurisdictions/workflows with task checkboxes.

Governance legal holds now select a bounded record category and search a permission-checked human record directory. Purge evidence remains stored and auditable, but its raw hash is not rendered. Offline provisioning now searches only role- and scope-authorised records, builds medical scope through a named schedule, and keeps device, package, event and entity identifiers hidden. Hard-copy checks are checklist rows, verification evidence is linked automatically, server snapshots are structured summaries, and conflicts show labelled records and formatted values rather than serialized objects.

New supporting endpoints are `GET /api/v1/admin/scope-options`, `GET /api/v1/selection/lookups`, `GET /api/v1/governance/legal-hold-targets`, and `GET /api/v1/offline/reference-options`. Each retains identifiers only as silent submitted values. Source scans and desktop/mobile browser coverage enforce that no free-form JSON editor or user-facing internal identifier remains in these workflows.
