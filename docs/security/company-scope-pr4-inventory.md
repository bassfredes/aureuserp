# PR 4 — Inventario global de company-scope (plugins restantes)

- Tracking: `bassfredes/Intelligent-Integration-Suite#138` (padre `#81`)
- Branch: `feat/company-scope-remaining-plugins`
- Base: `main` @ `ef7e6aaa8ca55d268288993a3d7f8231adc4479a` (squash PR #17)
- Estado: checkpoint de inventario/auditoría. **Ningún modelo de negocio fue modificado en este checkpoint** — ver "Estado" al final.

## Metodología

Auditoría ejecutada vía `php scripts/audit-company-scope.php` (PHP 8.4.23, dentro del contenedor `monorepo-aureuserp-1`), reescrito en este checkpoint para apoyarse en:

- `app/Support/CompanyScopeAudit/Auditor.php` — motor de inspección (extraído del script original para ser testeable en aislamiento).
- `app/Support/CompanyScopeAudit/ExceptionManifest.php` — lector del manifest de excepciones.
- `config/company-scope-exceptions.php` — el manifest mismo: única vía sancionada para silenciar un hallazgo `missing_scope`/`not_company_scoped`, por FQCN exacto (sin exclusiones por namespace/regex/nombre corto).

**Auto-discovery real**: sin `--plugins`, el script audita `plugins/webkul/*/src/Models` completo (26 plugins detectados en disco, orden determinista, excluye silenciosamente los que no tienen `src/Models` como `barcode`/`full-calendar`) — ya no un default hardcodeado de 3 plugins. `php scripts/audit-company-scope.php` sin argumentos es ahora una auditoría global real, no un falso verde parcial.

**Estados por fila**: `effective_status` colapsa el escaneo crudo + la clasificación del manifest en `scoped | classified_exception | real_gap_company_column | real_gap_without_company_column | table_missing | inspection_error`. `Auditor::isRealGap()` cubre AMBOS `missing_scope` (tiene `company_id`, sin `HasCompanyScope`) y `not_company_scoped` (sin `company_id`) cuando no hay una entrada de manifest válida — antes solo el primero contaba para `--fail-on-missing`, dejando huecos como `Milestone`/pivotes/children sin `company_id` fuera del gate.

El auditor valida el manifest completo en cada corrida (independiente del `--plugins` solicitado) y falla (`exit 2`) ante cualquiera de:

- `invalid_shape` — falta `table`/`classification`/`reason`/`tracking` no-vacíos, o `classification: alias` sin `alias_of`.
- `class_not_found` — la clase de una entrada ya no existe/autoload.
- `table_mismatch` — la tabla registrada no coincide con la tabla real del modelo.
- `invalid_classification` — la clasificación no es una de `global_party_identity|alias|global_reference|parent_scoped|not_tenancy|root_company_entity|multi_company_membership`.
- `stale_exception` — el modelo ya usa `HasCompanyScope` de verdad (basta `uses_company_scope === true`, sin importar `has_company_id`); la excepción sobra y debe eliminarse.
- `alias_chain_broken` — el `alias_of` no es realmente ancestro (`is_subclass_of`), o apunta a una clase sin entrada de manifest y no autoloadable.
- `alias_chain_cycle` — la cadena de `alias_of` se revisita a sí misma.
- `table_missing` / `inspection_error` (comportamiento preexistente).

Un gap real (sin entrada de manifest, `missing_scope` o `not_company_scoped`) solo hace fallar la corrida (`exit 1`) cuando se pasa `--fail-on-missing` — es trabajo pendiente conocido, no un auditor roto. Verificado explícitamente sobre la base fresh: `php scripts/audit-company-scope.php --format=json` → `exit 0` (0 table_missing, 0 inspection_errors, 0 manifest_violations, 78 gaps reales); el mismo comando `--fail-on-missing` → `exit 1` (78 gaps reales > 0).

Cobertura de tests: `tests/Feature/Support/CompanyScopeAuditorTest.php` (29 casos) — excepción válida, alias esperado, cadena de alias multi-hop real, `not_company_scoped` clasificado vs no clasificado, `missing_scope` no clasificado, `table_missing`, excepción stale (por `uses_company_scope` solo), tabla incorrecta, clasificación inválida, clase inexistente, shape inválido (4 variantes: cada campo requerido eliminado por completo), validación detenida tras shape inválido (sin segunda violación espuria), alias sin `alias_of`, cadena de alias rota (clase inexistente Y clase real-pero-no-registrada, casos distintos), ciclo de alias, manifest válido con tabla físicamente ausente en la corrida (sin violación), TableView/TableViewFavorite no silenciables vía manifest, gap sin company_id visible en `--format=table`, excepción sin company_id oculta en `--format=table`, `table_missing` siempre visible, discovery global determinista, `--plugins` focalizado, plugin inexistente solicitado.

## Checkpoint de esquema completo (fresh install aislado)

Base aislada `db_aureuserp_audit_fresh` (mismo servidor MySQL del entorno dev, base y usuario separados de `db_aureuserp`/`db_aureuserp_test`). Secuencia: `erp:install --force -n` + los 20 comandos `<plugin>:install -n` (accounting, accounts, barcode, blogs, contacts, employees, full-calendar, inventories, invoices, maintenance, manufacturing, payments, products, projects, purchases, recruitments, sales, time-off, timesheets, website) — todos exit 0.

Corrida final tras ola 4A, `php scripts/audit-company-scope.php --format=json` (auto-discovery, 26 plugins, sin `--plugins`) sobre la misma base fresh, committeada en `docs/security/company-scope-pr4-inventory.json`:

```json
{
  "total": 304,
  "scoped": 111,
  "classified_exceptions": 123,
  "real_gaps_with_company_id": 39,
  "real_gaps_without_company_id": 31,
  "table_missing": 0,
  "inspection_errors": 0,
  "manifest_violations": 0
}
```

`exit 0` sin `--fail-on-missing`; `exit 1` con `--fail-on-missing` (70 gaps reales = 39 + 31 > 0, todavía esperado — quedan olas futuras) — verificado explícitamente, no asumido.

| Corrida | table_missing | inspection_error | manifest_violations | gaps reales | exit (sin flag) | exit (`--fail-on-missing`) |
|---|---|---|---|---|---|---|
| DB dev existente (`db_aureuserp`), auto-discovery | 35 | 0 | 0 | — (no se completó, table_missing>0 es fatal) | 2 | 2 |
| DB aislada, fresh install completo (checkpoint, antes de ola 4A) | **0** | **0** | **0** | 78 | **0** | **1** |
| DB aislada, fresh install completo (después de ola 4A) | **0** | **0** | **0** | 70 | **0** | **1** |

`scoped` sube de 106 a 111 (+5: `Project`, `Task`, `TaskStage`, y ambas clases `Timesheet` — `Webkul\Project\Models\Timesheet` y `Webkul\Timesheet\Models\Timesheet`). `classified_exceptions` sube de 120 a 123 (+3: `TableView`, `TableViewFavorite`, `Milestone`). Gaps reales bajan de 78 a 70 (-8, exactamente los 5 `scoped` + 3 `classified_exceptions` nuevos).

## Manifest de excepciones — resumen

123 entradas, 0 violaciones (shape, tabla, clasificación, stale, cadena de alias) sobre el manifest completo. `TableView`/`TableViewFavorite` reingresaron al manifest en ola 4A tras cerrar el IDOR con un resolver server-side real (ver "Ola 4A" abajo); `Milestone` entró por primera vez, `parent_scoped` vía su Project obligatorio.

Conteo exacto (`config/company-scope-exceptions.php`, verificado por script, no a mano):

| Clasificación | Cantidad | Notas |
|---|---|---|
| `global_party_identity` | 1 | `Webkul\Partner\Models\Partner` (raíz canónica) |
| `alias` | 45 | Partner/Customer/Vendor/Address (15 aliases del grupo original) + Currency/Bank/Industry/Tag/Title/Incoterm/CashRounding/Category/Attribute/ProcurementGroup/UTMMedium/UTMSource/EmploymentType/SkillType/`Company`(security) — cadenas multi-hop verificadas (p.ej. `sales.Category → invoices.Category → accounts.Category → products.Category`) |
| `global_reference` | 38 | Currency, Country, State, Bank, UOM, UOMCategory, EmailTemplate, custom_fields, UTMMedium/Source/Stage, products Attribute/Category/AttributeOption/Tag, accounts Incoterm/CashRounding/Tag/PaymentMethod, inventories Tag, manufacturing WorkCenterLossType/Tag/ProductivityLoss, HR/recruitment lookup tables (EmployeeCategory, DepartureReason, EmployeeResumeLineType, EmploymentType, SkillType, SkillLevel, Skill, ApplicantCategory, Degree, RefuseReason), `maintenance.Stage` (contrato aprobado), `partners`/`contacts` Industry/Tag/Title, `projects.Tag` |
| `parent_scoped` | 25 | Pivotes/hijos de un parent YA `HasCompanyScope` o pivote-validado, con evidencia citada (p.ej. `sales.OrderLine` vía `ValidatesRelatedCompanyScope`, `accounts_account_*` pivots vía Journal/Tax/Move ya escopados, `manufacturing_work_orders/operations/work_center_capacities`, y desde ola 4A `Webkul\Project\Models\Milestone` vía su Project mandatorio) |
| `not_tenancy` | 12 | Permission, Role, Team(security), Plugin, EmailLog, Blog Category/Post/Tag, Website Page, ActivityTypeSuggestion, y desde ola 4A `TableView`/`TableViewFavorite` (estado de UI propio del usuario, IDOR cerrado vía resolver) |
| `root_company_entity` | 1 | `Webkul\Support\Models\Company` |
| `multi_company_membership` | 1 | `Webkul\Security\Models\User` |
| **Total** | **123** | |

`BankAccount` (4 clases) queda deliberadamente **fuera** del manifest — es un gap real pendiente del pivote `partners_bank_account_companies` del contrato aprobado, no una excepción. Ver sección 4.

Todos los modelos hijos/pivote de un parent que **todavía no está escopado** (Employee, JobPosition, Department, Candidate, Applicant, Calendar, ActivityPlan, LeaveType, Team de maintenance/sales) quedan deliberadamente fuera del manifest también — `parent_scoped` solo se usa cuando existe enforcement real y citable, nunca por adelantado. `Project`/`Task`/`TaskStage`/`Timesheet` ya no aparecen en esta lista de pendientes: desde ola 4A son `scoped` de verdad (`HasCompanyScope` + enforcement real en cada save), no manifest — ver "Ola 4A" abajo.

---

## Leyenda de clasificación (matriz narrativa, secciones 0-6)

- `owner standalone` — HasCompanyScope, company_id no-nulo, autorización de escritura en cada save, company_id inmutable.
- `child` — deriva compañía del parent persistido, sin columna propia o validada contra el parent.
- `global_party_identity` — Partner/Customer/Vendor y alias, decisión cerrada, NO tocar.
- `global_system_config` / `global_reference_data` — dato compartido por diseño (países, monedas, UOM, bancos, permisos, plantillas de email...), sin dimensión de compañía.
- `relational-no-company-with-pivot` — sin company_id, validado vía pivote M2M a Company.
- `multi_company_membership` — User (default_company_id + user_allowed_companies), es la raíz que CompanyScope::allowedCompanyIds() lee.
- `root_company_entity` — Company mismo, no se auto-escopa.
- **real gap** — requiere código de negocio, autorizado a landear en esta misma rama/PR #18 una vez el checkpoint quede aprobado (no en un PR separado).

---

## 0. Dominios ya cerrados (PR #17) — verificación, sin acción

71 filas dedupe a 45 tablas físicas. Todas resuelven a 4 patrones ya revisados:
1. Taxonomía/referencia global (products_attributes, products_categories, currencies, accounts_incoterms, accounts_cash_roundings, accounts_account_tags, manufacturing_work_center_loss_types/tags/productivity_losses, inventories_tags). `partners_bank_accounts` fue removida de esta lista — ver sección 4, es un gap real de PR 4, no una excepción cerrada.
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

**✅ Resuelto en ola 4A** (puntos 1-4 de esta lista): `Project`/`Task`/`TaskStage`/`Timesheet` (ambas clases) y `Milestone` — ver sección "Ola 4A" al final del documento. `ProjectStage` y `ActivityPlan` siguen pendientes de una ola futura.

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

### Gaps reales a escopar (código de negocio, esta misma rama/PR #18)
ActivityPlan/ActivityPlanTemplate (ver HR), Calendar, CalendarLeave (ver HR), CurrencyRate, UtmCampaign, Chatter Message/Attachment, Invitation (contrato aprobado).

---

## 4. Cluster Identity — partners, contacts (14 filas)

`partners` = capa de modelo/schema (dueña de TODAS las migraciones); `contacts` = capa de UI (subclases de 0 lógica). Ambos plugins son necesarios, no hay duplicado a resolver.

- `Partner`/`Address`/Customer/Vendor → 16 excepciones en el manifest: 1 `global_party_identity` (`Webkul\Partner\Models\Partner`, raíz canónica) + 15 `alias` (Address, y las plugin-scoped subclasses de Partner/Customer/Vendor).
- `Bank`, `Industry`, `Tag`, `Title` → clasificadas `global_reference` en el manifest de este checkpoint (sin company_id por diseño, sin FK a una entidad tenant-owned).
- **`BankAccount`** (`partners_bank_accounts`, 4 clases) → **gap real, deliberadamente fuera del manifest**. Contrato aprobado con pivote `partners_bank_account_companies`, ver "Decisiones de contrato" — no implementado en este checkpoint.

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
| `TableView`/`TableViewFavorite` | table_views/table_view_favorites | no | User-owned, lectura pública opcional | ✅ Resuelto en ola 4A |

### IDOR confirmado en TableView — contrato aprobado, ver "Decisiones de contrato"

`HasTableViews::getSavedTableViews()` no filtra por compañía. Más serio: `EditViewAction`/`deleteTableViewAction`/`replaceTableViewAction` resolvían `TableView::find($arguments['view_key'])` sin re-verificar `user_id` server-side — el chequeo de propiedad solo gateaba visibilidad del botón.

**✅ Resuelto en ola 4A** — ver sección "Ola 4A" al final del documento.

---

## Resumen de bypass patterns encontrados (todos los clusters)

- `Company::first()` / `Company::query()->value('id')` en 6+ seeders y en runtime real (`sales/OrderTemplateProduct.php:57`, dormido).
- `Auth::user()->default_company_id` usado en vez de un parent ya disponible: `TimeOff\Leave`, `TimeOff\LeaveAllocation`, `Project\Task` — el hallazgo más repetido y severo.
- Setting global único (`UserSettings::default_company_id`) determinando la compañía de usuarios recién invitados.
- Ningún seeder en todo el alcance de PR 4 usa `CompanyContext::runForX()` directamente — ver contrato aprobado sobre seeders/instaladores (NO es un defecto a corregir per-seeder).
- Dos IDOR confirmados por falta de autorización de propiedad/relación: `MilestoneController` (projects) y `EditViewAction`/`deleteTableViewAction` (table-views).

---

## Decisiones de contrato aprobadas (revisión #138, comentario `5016816710`) — pendientes de implementación

Estas decisiones quedaron cerradas en la revisión de este checkpoint. **Ningún código de negocio fue tocado todavía** — quedan como contrato autorizado para implementarse en esta misma rama (`feat/company-scope-remaining-plugins`, PR #18) una vez el checkpoint quede aprobado. No se abre un PR adicional para PR 4.

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

## Correcciones de la segunda revisión (#138, revisión `4731774794`)

1. **`TableView`/`TableViewFavorite` retiradas del manifest.** Clasificarlas silenciaba el IDOR confirmado (ownership check pendiente en `EditViewAction`/`deleteTableViewAction`/`replaceTableViewAction`) — un contrato aprobado pero no implementado no puede desaparecer del conteo de gaps solo por declarar una clasificación. Ambas vuelven a `real_gap_without_company_column`. Test dedicado: `it('does not let a manifest exception silence a model with an approved-but-unimplemented contract (TableView)')`.
2. **Validación de manifest separada en estática vs física.** `validateManifest()` ya no llama a `Schema::hasTable()`/`hasColumn()` — solo reflection pura (`class_exists`, `getTable()`, `class_uses_recursive`, `is_subclass_of`). Una corrida `--plugins=A` con una entrada de manifest para un modelo del plugin B, cuya tabla no está migrada en ese esquema, ya no produce `manifest_violation`. `table_missing` sigue siendo exclusivamente responsabilidad de `inspectClass()`, acotado a lo realmente inspeccionado. Test: `it('does not require a manifest entry\'s table to physically exist...')`.
3. **La validación se detiene tras `invalid_shape`.** Una entrada con shape inválido (clave faltante) ya no cae en `validateEntryAgainstReflection()`, que asumía la forma válida. 4 tests nuevos eliminan por completo cada una de `table`/`classification`/`reason`/`tracking` y confirman que solo se reporta `invalid_shape`, sin una segunda violación espuria.
4. **Cadena de alias exige terminal registrado.** `validateAliasChain()` ya no acepta como válido un `alias_of` que apunte a una clase real, autoloadable y de la misma tabla si esa clase no tiene su propia entrada en el manifest — ahora es siempre `alias_chain_broken`. Test dedicado distingue este caso del de "clase totalmente inexistente".
5. **Salida `--format=table` ya no oculta gaps sin `company_id`.** Nuevo `Auditor::shouldDisplayInTable()` — visible si tiene `company_id`, o si es cualquier `real_gap_*`, o si es `table_missing`/`inspection_error`; solo se ocultan por defecto las excepciones clasificadas sin `company_id` (referencia global, alias, not_tenancy). Verificado con `--plugins=table-views`: ambas filas aparecen ahora como `real_gap_without_company_column`.
6. **CI corregido y con verificación de drift exacta.** El job `Company Scope Global Audit` fallaba en `Running Composer Install` porque `filament:upgrade` (hook `post-autoload-dump`) intenta copiar assets de `resources/dist/` que solo existen tras el job `frontend_assets` — faltaban `needs: frontend_assets` y el step `Download built plugin assets`, ya agregados. El gate de `total >= 300` fue reemplazado por un `diff -u` exacto entre el JSON recién generado y `docs/security/company-scope-pr4-inventory.json` committeado — cualquier drift (modelo escopado, excepción agregada/quitada, tabla renombrada) rompe el CI hasta que el inventario se regenere y se commitee en el mismo cambio.

Números actualizados tras estas correcciones (120 excepciones, no 122 — las 2 de TableView/TableViewFavorite salieron del manifest): ver el bloque JSON al inicio de este documento.

---

## Ola 4A — TableView/TableViewFavorite, Project, Task, TaskStage, Milestone, Timesheet

Autorizada tras el checkpoint aprobado (revisión `4736684439` de PR #18, mismo head `1ded2ae96`). Implementa los contratos de negocio descritos en "Decisiones de contrato aprobadas" para Table Views y Projects (secciones "Projects" y "Table Views" arriba), landeando en la misma rama/PR #18.

### TableView / TableViewFavorite

IDOR cerrado con un único resolver server-side reutilizado por editar, reemplazar y eliminar:

```php
TableView::resolveOwnedTableViewOrFail(int $viewId, string $filterableType, int $userId): TableView
```

Una sola consulta exige simultáneamente `id`, `filterable_type` y `user_id` del actor — una vista pública ajena nunca resuelve por esta vía (solo lectura, vía `getSavedTableViews()` que ya filtraba propio+público). `TableView::assertVisibleOrFail()` cubre el caso de lectura/favorito (propio o público). `TableViewFavorite::toggleForOwnViewOrFail()` fuerza siempre `user_id = Auth::id()` (nunca un valor de la request) y rechaza favoritear una vista privada ajena. Wired en `HasTableViews::deleteTableViewAction/replaceTableViewAction/add|removeTableViewToFavoritesAction` y en `EditViewAction::fillForm()/action()`. 11 tests en `plugins/webkul/table-views/tests/Feature/TableViewOwnershipTest.php`. Ambos modelos reingresan al manifest como `not_tenancy` (nunca tendrán `company_id` — es aislamiento por usuario, no por tenant) con la evidencia del resolver citada en `config/company-scope-exceptions.php`.

### Project

`HasCompanyScope` + `HasStrictCompanyId` (mismo patrón que `Journal`/`PaymentTerm`/`FiscalPosition`): `company_id` obligatorio (se autocompleta desde `default_company_id` del actor solo si viene vacío — nunca sobrescribe un valor explícito no autorizado), autorización en cada save vía `CompanyScope::assertCanWriteCompany()`, inmutable tras creación ("archive and recreate"). Fail-closed sin usuario/contexto. 10 tests en `ProjectCompanyScopeTest.php`.

### Task / TaskStage

`company_id` se deriva del `Project` persistido (nunca de una relación en memoria) vía `ValidatesRelatedCompanyScope::resolveEffectiveCompanyIdOrFail()`, ya usado en el dominio de accounts (PR #17) para el mismo patrón madre→hijo. Un `project_id` inexistente o cuyo Project no tiene compañía falla cerrado. Un cambio de `project_id` (solo, o junto con un `company_id` explícito y consistente) que implique traslado de tenant se rechaza — comparando el `company_id` efectivo recién resuelto contra `getOriginal('company_id')`, el mismo mecanismo de inmutabilidad que `Project`, pero derivado en vez de directo. `Task` tolera `project_id = null` (columna nullable, `nullOnDelete` — una tarea huérfana tras borrar su Project) reautorizando su propio `company_id` ya persistido en vez de fallar en cada save futuro; `TaskStage.project_id` es NOT NULL (`cascadeOnDelete`), así que siempre deriva. 12 + 7 tests (`TaskCompanyScopeTest.php`, `TaskStageCompanyScopeTest.php`).

### Milestone

Sin columna `company_id` propia (su Project padre es obligatorio, `cascadeOnDelete`). Lectura: `Milestone::booted()` agrega un global scope `whereHas('project')` — el scope `HasCompanyScope` de `Project` se aplica automáticamente dentro de esa subquery, así que un Milestone cuyo Project está oculto (compañía equivocada, o sin usuario/contexto — `CompanyScope` falla cerrado) también queda oculto. Escritura: `resolveEffectiveCompanyIdOrFail()` sobre el `project_id`, descartando el valor de retorno (no hay columna donde persistirlo) — el efecto es puramente de autorización: Project debe existir, tener compañía propia, y el actor debe estar autorizado para escribir en ella. `MilestonePolicy::view/update/delete` ahora también re-derivan la compañía efectiva desde el Project persistido (`belongsToAllowedCompany()`) e la comparan contra las compañías permitidas del actor — una ability genérica de Spatie ya no es suficiente por sí sola, cerrando el IDOR también a nivel de policy, no solo de resolver. 8 tests (`MilestoneCompanyScopeTest.php`).

### Timesheet (`Webkul\Project\Models\Timesheet` y su subclase vacía `Webkul\Timesheet\Models\Timesheet`)

Valida el grafo completo Timesheet → Task → Project → company: `company_id` se deriva del `Task` persistido (mismo patrón que Task deriva de Project); si además trae un `project_id` explícito, debe coincidir con el `project_id` del Task referenciado (una Task de Project A con un Timesheet apuntando a Project B es un grafo espurio, rechazado aunque ambos sean de la misma compañía). Reasignar `task_id` a una Task de otra compañía se rechaza igual que Task/TaskStage. `task_id = null` (huérfana tras borrar su Task) reautoriza su propio `company_id`. 8 tests (`TimesheetCompanyScopeTest.php`).

### Regresión de fixtures (no de producción)

`TaskStageFactory`/`TaskFactory` tenían un `'company_id' => Company::factory()` independiente de su `'project_id' => Project::factory()` — dos compañías aleatorias no relacionadas, inofensivo mientras `Project`/`TaskStage` no tenían enforcement real. Con el enforcement de esta ola, esa inconsistencia rompía 16 tests preexistentes de `plugins/webkul/projects/tests/Feature/API/V1/{TaskStageTest,TaskTest}.php`. Corregido quitando el `company_id` independiente de ambas factories (mismo patrón ya usado en `MoveLineFactory` desde PR #17: "dejar sin asignar, el propio saving() hook lo deriva"). Suite completa de `plugins/webkul/projects` tras el fix: 116/116 verde.

### Cobertura total de esta ola

46 tests nuevos (`ProjectCompanyScopeTest`, `TaskCompanyScopeTest`, `TaskStageCompanyScopeTest`, `MilestoneCompanyScopeTest`, `TimesheetCompanyScopeTest`, `TableViewOwnershipTest`), cubriendo por cada modelo: aislamiento de lectura (actor ve solo su(s) compañía(s)), lista vacía sin compañías, fail-closed sin actor/contexto, creación/actualización cross-company rechazadas, reasignación rechazada (donde aplica), al menos una prueba con `withoutGlobalScope()` demostrando que la autorización de escritura no depende del scope de lectura, y `CompanyContext::runForCompany/runForAllCompanies/runForBootstrap` operando correctamente (Project/Task). `--fail-on-missing` sigue retornando `1` (70 gaps reales restantes — Time Off, Invitations, BankAccount, Maintenance, y otros hijos de parents aún no escopados quedan para olas futuras).

---

## Estado

```
PR 4 (PR #18, feat/company-scope-remaining-plugins): checkpoint aprobado + ola 4A implementada
  - checkpoint (auditor/manifest/CI): sin cambios respecto a la 2a/3a/4a revisión, ver arriba
  - ola 4A: TableView/TableViewFavorite (IDOR cerrado, resolver server-side), Project
    (HasCompanyScope + HasStrictCompanyId), Task/TaskStage (derivación desde Project,
    ValidatesRelatedCompanyScope), Milestone (parent-scoped vía whereHas + policy),
    Timesheet ambas clases (grafo Task→Project→company validado)
  - 123 excepciones formalizadas (1 global_party_identity, 45 alias, 38 global_reference,
    25 parent_scoped, 12 not_tenancy, 1 root_company_entity, 1 multi_company_membership)
  - docs/security/company-scope-pr4-inventory.json regenerado (304 filas): scoped 106→111,
    classified_exceptions 120→123, gaps reales 78→70
  - 46 tests nuevos + 116/116 verde en la suite completa de plugins/webkul/projects (incluye
    2 factories corregidas — company_id independiente del project_id, ver "Regresión de fixtures")
  - CI: 4/4 verde sobre el nuevo head, diff exacto de inventario sin drift inesperado
Modelos de negocio aún no tocados (próximas olas): Time Off/Leave, Invitations, BankAccount,
  Maintenance, ProjectStage, ActivityPlan, y todo hijo/pivote de un parent aún no escopado
PR adicional para PR 4: prohibido — los cambios de negocio landean en esta misma rama/PR #18
PR 5: no autorizada
#138 / #81: abiertos
AGENTS.md: stashes intactos (ambos checkouts)
```
