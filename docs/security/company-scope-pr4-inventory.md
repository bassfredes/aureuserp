# PR 4 — Inventario global de company-scope (plugins restantes)

- Tracking: `bassfredes/Intelligent-Integration-Suite#138` (padre `#81`)
- Branch: `feat/company-scope-remaining-plugins`
- Base: `main` @ `ef7e6aaa8ca55d268288993a3d7f8231adc4479a` (squash PR #17)
- Estado: checkpoint de inventario/auditoría. **Ningún modelo de negocio fue modificado en este checkpoint** — ver "Estado" al final.

## Metodología

Auditoría ejecutada vía `php scripts/audit-company-scope.php` (PHP 8.4.23, dentro del contenedor `monorepo-aureuserp-1`), reescrito en este checkpoint para apoyarse en:

- `app/Support/CompanyScopeAudit/Auditor.php` — motor de inspección (extraído del script original para ser testeable en aislamiento).
- `app/Support/CompanyScopeAudit/ExceptionManifest.php` — lector del manifest de excepciones.
- `config/company-scope-exceptions.php` — el manifest mismo: única vía sancionada para silenciar un hallazgo `missing_scope`, por FQCN exacto (sin exclusiones por namespace/regex/nombre corto).

El auditor valida el manifest completo en cada corrida (independiente del `--plugins` solicitado) y falla (`exit 2`) ante cualquiera de:

- `class_not_found` — la clase de una entrada ya no existe/autoload.
- `table_mismatch` — la tabla registrada no coincide con la tabla real del modelo.
- `invalid_classification` — la clasificación no es una de `global_party_identity|alias|global_reference|parent_scoped|not_tenancy`.
- `stale_exception` — el modelo ya usa `HasCompanyScope` de verdad; la excepción sobra y debe eliminarse.
- `table_missing` / `inspection_error` (comportamiento preexistente).

Un `missing_scope` real (sin entrada de manifest) solo hace fallar la corrida (`exit 1`) cuando se pasa `--fail-on-missing` — es trabajo pendiente conocido, no un auditor roto.

Cobertura de tests: `tests/Feature/Support/CompanyScopeAuditorTest.php` (8 casos): excepción válida, alias esperado, excepción stale, tabla incorrecta, missing real sin excepción, table_missing, clasificación inválida, clase inexistente.

## Checkpoint de esquema completo (fresh install aislado)

Base aislada `db_aureuserp_audit_fresh` (mismo servidor MySQL del entorno dev, base y usuario separados de `db_aureuserp`/`db_aureuserp_test`). Secuencia: `erp:install --force -n` + los 20 comandos `<plugin>:install -n` (accounting, accounts, barcode, blogs, contacts, employees, full-calendar, inventories, invoices, maintenance, manufacturing, payments, products, projects, purchases, recruitments, sales, time-off, timesheets, website) — todos exit 0.

| Corrida | table_missing | inspection_error | manifest_violations | missing_scope (unclassified) | exit |
|---|---|---|---|---|---|
| DB dev existente (`db_aureuserp`), 26 plugins con `src/Models/` | 35 | 0 | 0 | 41 | 2 (por table_missing) |
| DB aislada, fresh install completo | **0** | **0** | **0** | 47 | **0** |

El delta de 41→47 `missing_scope` se reconcilia exactamente: los 6 modelos que pasaron de `table_missing` a `missing_scope` real son `maintenance.Equipment/EquipmentCategory/MaintenanceRequest/Team` y `recruitments.Applicant/Candidate` — ya identificados como gaps reales en la matriz (secciones 1 y 5). Ningún hallazgo nuevo, ninguna sorpresa.

## Manifest de excepciones — resumen

16 entradas, todas `partners_partners` (Partner/Customer/Vendor/Address), 0 violaciones en ambas corridas:

| Clasificación | Cantidad | FQCNs |
|---|---|---|
| `global_party_identity` | 1 | `Webkul\Partner\Models\Partner` (raíz canónica) |
| `alias` | 15 | `Webkul\Contact\Models\Partner`, `Webkul\Contact\Models\Address`, `Webkul\Partner\Models\Address`, `Webkul\Accounting\Models\{Partner,Customer,Vendor}`, `Webkul\Account\Models\{Partner,Customer,Vendor}`, `Webkul\Invoice\Models\{Partner,Customer,Vendor}`, `Webkul\Purchase\Models\Partner`, `Webkul\Sale\Models\Partner`, `Webkul\Website\Models\Partner` |
| `global_reference` | 0 | — (pendiente: Bank/Industry/Tag/Title de partners/contacts, ver sección 4 — no clasificados aún en el manifest, siguen como `not_company_scoped` informativo) |
| `parent_scoped` | 0 | — (pendiente para próxima ronda) |
| `not_tenancy` | 0 | — (pendiente para próxima ronda) |

Este checkpoint solo formaliza la decisión ya cerrada de PR #17 (Partner/Customer/Vendor). Las clasificaciones `global_reference`/`parent_scoped`/`not_tenancy` para el resto de los 113 `not_company_scoped` (Bank, Country, UOM, Tag×N, etc.) quedan para cuando se autoricen los PRs de negocio correspondientes — no se backfillearon aquí para no exceder el alcance de "solo checkpoint de auditoría".

---

## Leyenda de clasificación (matriz narrativa, secciones 0-6)

- `owner standalone` — HasCompanyScope, company_id no-nulo, autorización de escritura en cada save, company_id inmutable.
- `child` — deriva compañía del parent persistido, sin columna propia o validada contra el parent.
- `global_party_identity` — Partner/Customer/Vendor y alias, decisión cerrada, NO tocar.
- `global_system_config` / `global_reference_data` — dato compartido por diseño (países, monedas, UOM, bancos, permisos, plantillas de email...), sin dimensión de compañía.
- `relational-no-company-with-pivot` — sin company_id, validado vía pivote M2M a Company.
- `multi_company_membership` — User (default_company_id + user_allowed_companies), es la raíz que CompanyScope::allowedCompanyIds() lee.
- `root_company_entity` — Company mismo, no se auto-escopa.
- **real gap** — requiere acción en un próximo PR de negocio.

---

## 0. Dominios ya cerrados (PR #17) — verificación, sin acción

71 filas dedupe a 45 tablas físicas. Todas resuelven a 4 patrones ya revisados:
1. Taxonomía/referencia global (products_attributes, products_categories, currencies, accounts_incoterms, partners_bank_accounts, accounts_cash_roundings, accounts_account_tags, manufacturing_work_center_loss_types/tags/productivity_losses, inventories_tags).
2. Pivotes/hijos cuyo único FK apunta a un parent ya `HasCompanyScope` (accounts_account_* pivots, inventories_package_destinations, inventories_procurement_groups, purchases_order_groups, manufacturing_work_orders/work_center_capacities/operations — estos 3 últimos con comentario explícito "#138 review round 2, 2026-07-18" en código).
3. `accounts_accounts` (Account) — sin company_id propio, validado vía pivote M2M `accounts_account_companies` (confirmado en `accounts/src/Models/Account.php:111,200`).
4. Los 9 `missing_scope` de Partner/Customer/Vendor documentados en PR #17 — ahora formalizados en el manifest (junto con 7 aliases adicionales de otros plugins no cubiertos por esa auditoría más angosta, ver arriba).

Los 13 `table_missing` de `manufacturing` en la corrida sobre `db_aureuserp` eran solo entorno (confirmado por el checkpoint de fresh install: sus tablas existen y ya tienen `HasCompanyScope` en código, con comentario de ronda de revisión).

Dos notas menores no bloqueantes: `inventories.PackageDestination`/`ProductQuantityRelocation` no tienen consumidores en todo el código (posibles modelos huérfanos); `inventories_tags` no está nombrada explícitamente en la sección de `inventories` del plan doc (`docs/plans/2026-07-07-company-scope-rollout.md`), aunque el patrón es idéntico a otros 4 Tag ya aceptados.

**Veredicto: nada requiere código de negocio para este bloque.**

---

## 1. Cluster HR — employees, time-off, recruitments (49 filas)

Tablas compartidas cruzando plugins (un solo owner físico, resto son alias sin lógica):
- `activity_plans`/`activity_types` → owner real: **support** (`Webkul\Support\Models\ActivityPlan/ActivityType`)
- `calendars`/`calendar_attendances`/`calendar_leaves` → owner real: **support** (renombradas desde `employees_*` en migración `2026_04_02_000001`)
- `utm_mediums`/`utm_sources` → owner real: **support**
- `employees_job_positions`/`employees_departments`/`employees_employment_types`/`employees_skill_types` → owner real: **employees** (recruitments solo ALTERs + subclases)

### Hallazgo más severo del cluster (CRÍTICO) — contrato aprobado, ver sección "Decisiones de contrato"

`TimeOff\Leave` y `TimeOff\LeaveAllocation` tienen `employee_id` **NOT NULL** pero derivan `company_id`/`employee_company_id` de `Auth::user()->default_company_id` (el actor), nunca de `$employee->company_id` (el parent ya resuelto):
- `plugins/webkul/time-off/src/Traits/TimeOffHelper.php:445-449` (`updateEmployeeAndCompanyData()`)
- `plugins/webkul/time-off/src/Models/LeaveAllocation.php:151-159`

### Otros gaps reales

| Modelo | Tabla | Riesgo | Acción |
|---|---|---|---|
| `Support\ActivityPlan` (alias employees/recruitments) | activity_plans | Alto | HasCompanyScope en la base `Support`, no en los alias |
| `Support\Calendar` (alias employees) | calendars | Medio | HasCompanyScope en la base; falta auto-fill company_id en boot() |
| `Support\CalendarLeave` (alias employees/time-off) | calendar_leaves | Alto | HasCompanyScope; decidir strict vs `IncludesSharedCompanyRows` (filas company_id NULL parecen feriados compartidos) |
| `Employee\Department` (+ alias recruitments) | employees_departments | Alto | HasCompanyScope + reauth en save + validar parent_id/master_department_id no cruce de compañía |
| `Employee\Employee` | employees_employees | Crítico | HasCompanyScope; es ancla de TimeOff/Recruitment |
| `Employee\EmployeeJobPosition` (+ alias/subclase recruitments) | employees_job_positions | Alto | HasCompanyScope + validar company_id vs department.company_id (hoy pueden divergir) |
| `Employee\WorkLocation` | employees_work_locations | Alto | company_id NOT NULL en DB pero sin autorización — Select abierto sin restricción |
| `Recruitment\Applicant` | recruitments_applicants | Alto | HasCompanyScope + validar candidate_id/job_id/department_id; además bug de clave duplicada en `createEmployee()` descarta el company_id propio |
| `Recruitment\Candidate` | recruitments_candidates | Alto | HasCompanyScope (mismo patrón de auto-provisión de Partner que Employee) |
| `TimeOff\LeaveAccrualPlan` | time_off_leave_accrual_plans | Alto | HasCompanyScope; ojo: `onDelete('cascade')` en company_id (anómalo, borra planes si se borra la Company) |
| `TimeOff\LeaveMandatoryDay` | time_off_leave_mandatory_days | Alto | HasCompanyScope |
| `TimeOff\LeaveType` | time_off_leave_types | Alto | HasCompanyScope; decidir strict vs shared (tipos como "Enfermedad" pueden ser globales) |
| `Recruitment\Stage` | recruitments_stages | Bajo-Medio | Decisión de producto: global vs por-compañía (hoy sin company_id) |

### Bypass patterns
`Company::first()` en 4 seeders (DepartmentSeeder, WorkLocationSeeder, AccrualPlanSeeder, LeaveTypeSeeder). Ver "Seeders e instaladores" en Decisiones de contrato — esto ya NO se considera un bypass a corregir por seeder individual, ver contrato aprobado.

### Bugs no relacionados a scope (flag, no bloqueante)
- `Applicant::createEmployee()`: clave de array duplicada `'company_id'`, se sobrescribe silenciosamente con `candidate.company_id`.
- `LeaveAllocation::creator()` apunta a columna `user_id` que no existe en la tabla.

---

## 2. Cluster PM/Analytics — projects, timesheets, analytics (10 filas)

`analytic_records` es tabla física única con herencia STI: `Analytic\Record` (base, owner real) ← `Project\Timesheet extends Record` ← `Timesheet\Timesheet extends Project\Timesheet` (subclase vacía). Un solo fix en `Record::boot()` resuelve las 3 clases.

### Hallazgos críticos — contrato aprobado, ver sección "Decisiones de contrato"

- **`Project` no tiene CompanyScope** (solo `UserPermissionScope`, que es visibilidad usuario/equipo, no aislamiento de tenant).
- **IDOR confirmado en `MilestoneController`**: `show/update/destroy` hacen `Milestone::findOrFail($id)` sin ningún chequeo de compañía/pertenencia; la policy solo valida una ability de Spatie, nunca ownership por registro.
- **Timesheet/`analytic_records` nunca recibe company_id al crear** — el único lugar que lo backfilla es el hook `updated()` de `Task`.
- `Task.company_id` se deriva de `Auth::user()->default_company_id`, nunca del `project_id` ya seleccionado en el mismo formulario.

### Prioridad de fix (orden del propio agente, confirmado en la revisión)
1. `Project` (raíz) — 2. Cadena Timesheet — 3. `Milestone` (IDOR) — 4. `Task`/`TaskStage` — 5. `ProjectStage` (`IncludesSharedCompanyRows`) — 6. `ActivityPlan` (fix pertenece a Support) — 7. `Tag` (sin acción).

---

## 3. Cluster Platform — chatter, security, support (30 filas)

### Hallazgos clave

1. **`Security\Models\Company` es código muerto** — subclase vacía de 1 línea, cero referencias fuera de su propia policy huérfana. La clase canónica real es `Support\Models\Company` (228 usos).
2. **`Support\Models\Company` (la real) correctamente no se auto-escopa** — entidad raíz de tenant, protegida por permisos Filament-Shield.
3. **`User` es la raíz de `multi_company_membership`** (`default_company_id` + pivote `user_allowed_companies`). Sin gap propio.
4. **Gap real en flujo de invitaciones — contrato aprobado**, ver "Decisiones de contrato".
5. **`Chatter\Attachment.company_id` es columna muerta** — existe pero ningún call-site la puebla.
6. **`CurrencyRate`** — candidato claro a `IncludesSharedCompanyRows`.
7. **`UtmCampaign`** es la única del grupo UTM que debería escoparse pero no lo hace.

### Clasificación global_system_config (sin acción)
Permission, Role (catálogo RBAC), Bank, Country, State, Currency, UOM, UOMCategory, EmailTemplate, UTMMedium, UTMSource.

### Gaps reales a escopar (próximo PR de negocio)
ActivityPlan/ActivityPlanTemplate (ver HR), Calendar, CalendarLeave (ver HR), CurrencyRate, UtmCampaign, Chatter Message/Attachment, Invitation (contrato aprobado).

---

## 4. Cluster Identity — partners, contacts (14 filas)

`partners` = capa de modelo/schema (dueña de TODAS las migraciones); `contacts` = capa de UI (subclases de 0 lógica). Ambos plugins son necesarios, no hay duplicado a resolver.

- `Partner`/`Address`/Customer/Vendor aliases → `global_party_identity`, formalizado en el manifest (16 entradas).
- `Bank`, `Industry`, `Tag`, `Title` → `global_reference_data`, sin company_id — pendiente de entrada explícita en el manifest en la próxima ronda.
- **`BankAccount`** (`partners_bank_accounts`) → contrato aprobado con pivote `partners_bank_account_companies`, ver "Decisiones de contrato".

---

## 5. Cluster Commerce — sales, maintenance, payments (22 filas)

- **`maintenance`**: contrato aprobado, ver "Decisiones de contrato" — `Stage` queda referencia global; `Team`/`EquipmentCategory`/`Equipment`/`MaintenanceRequest` quedan `strict_company`.
- **`payments`** (PaymentToken/PaymentTransaction) — dormido, sin write path actual. Escopar como prerequisito bloqueante antes de conectar cualquier gateway real.
- **`sales.Team`** — mismo gap vivo que `maintenance.Team`.
- **Bypass explícito**: `OrderTemplateProduct::boot()` usa `Company::first()?->id`/`Product::first()?->id`/`UOM::first()?->id` — dormido (Filament Resource huérfano), pero mismo anti-patrón que este rollout cierra en otros lados.
- `OrderLine` (sales) ya correctamente implementado (child + `ValidatesRelatedCompanyScope`) — falso positivo del auditor, sin acción.

---

## 6. Cluster Infra — blogs, website, table-views, fields, plugin-manager (9 filas)

| Modelo | Tabla | company_id | Clasificación | Acción |
|---|---|---|---|---|
| `Blog\Category/Post/Tag` | blogs_categories/posts/tags | no | Contenido de sitio único, global por diseño | Ninguna |
| `Website\Page` | website_pages | no | Igual que Blog | Ninguna |
| `Website\Partner` | partners_partners | — (alias) | `global_party_identity`, excepción de portal Customer (ADR 0007) | Ninguna — decisión cerrada |
| `Field` (fields) | custom_fields | no | Definiciones de campo custom (schema), `global_system_config` | Ninguna |
| `Plugin` (plugin-manager) | plugins | no | Registro de instalación a nivel de sistema | Ninguna |
| `TableView`/`TableViewFavorite` | table_views/table_view_favorites | no | User-owned, lectura pública opcional | Contrato aprobado, ver abajo |

### IDOR confirmado en TableView — contrato aprobado, ver "Decisiones de contrato"

`HasTableViews::getSavedTableViews()` no filtra por compañía. Más serio: `EditViewAction`/`deleteTableViewAction`/`replaceTableViewAction` resuelven `TableView::find($arguments['view_key'])` sin re-verificar `user_id` server-side — el chequeo de propiedad solo gatea visibilidad del botón.

---

## Resumen de bypass patterns encontrados (todos los clusters)

- `Company::first()` / `Company::query()->value('id')` en 6+ seeders y en runtime real (`sales/OrderTemplateProduct.php:57`, dormido).
- `Auth::user()->default_company_id` usado en vez de un parent ya disponible: `TimeOff\Leave`, `TimeOff\LeaveAllocation`, `Project\Task` — el hallazgo más repetido y severo.
- Setting global único (`UserSettings::default_company_id`) determinando la compañía de usuarios recién invitados.
- Ningún seeder en todo el alcance de PR 4 usa `CompanyContext::runForX()` directamente — ver contrato aprobado sobre seeders/instaladores (NO es un defecto a corregir per-seeder).
- Dos IDOR confirmados por falta de autorización de propiedad/relación: `MilestoneController` (projects) y `EditViewAction`/`deleteTableViewAction` (table-views).

---

## Decisiones de contrato aprobadas (revisión #138, comentario `5016816710`) — pendientes de implementación

Estas decisiones quedaron cerradas en la revisión de este checkpoint. **Ningún código de negocio fue tocado todavía** — quedan como contrato autorizado para el próximo PR de negocio.

### Time Off

```
Leave:
- strict_company sobre company_id
- company_id deriva de Employee.company_id
- employee_company_id debe ser igual a Employee.company_id
- actor no determina la compañía
- manager, approvers y Department deben ser compatibles

LeaveAllocation:
- child tenant-owned anclado en Employee
- employee_company_id deriva de Employee.company_id
- scope relacional por Employee/employee_company_id
- no agregar un company_id duplicado solo para satisfacer el auditor
```

### Projects

```
Project: root strict_company
Task: strict_company derivado de Project
TaskStage: strict_company derivado de Project obligatorio
Milestone: child parent-scoped por Project
Timesheet: strict mediante Analytic\Record y derivación Task/Project
```

`Task` deja de derivar de `Auth::user()->default_company_id`; deriva de `project_id`. El IDOR de Milestone se cierra en dos niveles: scope parent-aware en Milestone + policy server-side contra Project/compañía (no basta con corregir solo el controller).

### Table Views

Clasificación: `TableView` no es company-scoped, es user-owned con lectura pública opcional.

```
vista propia privada → leer y modificar
vista propia pública → leer y modificar
vista pública ajena → solo leer
vista privada ajena → no resolver
```

Edit, replace y delete deben resolver server-side vía un resolver único (no tres implementaciones separadas):

```php
TableView::query()
    ->whereKey($viewId)
    ->where('filterable_type', static::class)
    ->where('user_id', Auth::id())
    ->firstOrFail();
```

### Maintenance

```
Stage: referencia global
Team: strict_company
EquipmentCategory: strict_company
Equipment: strict_company
MaintenanceRequest: strict_company
```

`MaintenanceRequest` debe validar que Equipment/Team/EquipmentCategory pertenezcan a su compañía; su réplica recurrente debe conservar y reautorizar la compañía persistida. Seleccionar el primer Stage global es aceptable solo porque Stage queda clasificado como referencia global.

### Invitaciones

Migración nueva en `user_invitations`: `company_id`, `role_id`, `invited_by`, `expires_at`, `accepted_at`/`consumed_at`.

Al emitir: `company_id` = compañía operativa autorizada del invitante, `role_id` = rol elegido/default, `invited_by` = actor, `expires_at` = límite explícito.

Al aceptar: URL temporal firmada + transacción + `lockForUpdate()` de Invitation + validar no consumida/no expirada + crear User con la compañía capturada + asociar `allowedCompanies` + asignar el rol capturado + marcar consumida.

### BankAccount

`BankAccount` permanece hijo de `global_party_identity` — no recibe `HasCompanyScope` ni `company_id` strict. Se agrega tabla de membresía:

```
partners_bank_account_companies
- bank_account_id
- company_id
- unique(bank_account_id, company_id)
```

Reglas: creación desde una compañía habilita esa membresía; `PaymentRegister` exige BankAccount habilitada para su compañía persistida; `partner_bank_id` debe pertenecer a `partner_id`; `Employee.bank_account_id` debe estar habilitada para `Employee.company_id`; consultas tenant-facing filtran por el pivote; procesos sin actor requieren compañía/contexto explícito.

Backfill determinista desde `Employee.company_id + bank_account_id`, `PaymentRegister.company_id + partner_bank_id` y otros owners tenant identificados en esta matriz. Una fila no utilizada o ambigua queda sin membresía (inaccesible hasta remediación explícita) — nunca usar `Partner.company_id`, `creator.default_company_id` ni `Company::first()` como fallback.

### Seeders e instaladores

**Corrección respecto al hallazgo inicial**: no es necesario que cada seeder invoque `CompanyContext::runForX()` individualmente. `InstallCommand::handle()` (`plugins/webkul/plugin-manager/src/Console/Commands/InstallCommand.php:57-82`) ya envuelve migraciones y seeders completos en `CompanyContext::runForBootstrap()`, y reutiliza el contexto ya abierto cuando instala dependencias recursivamente (evita nesting prohibido).

```
seeders llamados por plugin:install → context-neutral, heredan bootstrap
seeder/command/job ejecutable standalone → abre CompanyContext en su boundary
factory → no abre contextos por sí sola
```

No envolver seeders individuales en `runForBootstrap()` — produciría reentrancia prohibida.

---

## Estado

```
PR 4: autorizada, checkpoint de auditoría completado
Modelos de negocio (Leave, Project, TableView, Invitation, BankAccount, Maintenance, etc.): aún no modificados
PR 5: no autorizada
#138 / #81: abiertos
AGENTS.md: stashes intactos (ambos checkouts)
```
