# PR 4: Inventario global de company-scope (plugins restantes)

- Tracking: `bassfredes/Intelligent-Integration-Suite#138` (padre `#81`)
- Branch: `feat/company-scope-remaining-plugins`
- Base: `main` @ `ef7e6aaa8ca55d268288993a3d7f8231adc4479a` (squash PR #17)
- Estado: checkpoint de inventario/auditoría. **Ningún modelo de negocio fue modificado en este checkpoint**: ver "Estado" al final.

## Metodología

Auditoría ejecutada vía `php scripts/audit-company-scope.php` (PHP 8.4.23, dentro del contenedor `monorepo-aureuserp-1`), reescrito en este checkpoint para apoyarse en:

- `app/Support/CompanyScopeAudit/Auditor.php`: motor de inspección (extraído del script original para ser testeable en aislamiento).
- `app/Support/CompanyScopeAudit/ExceptionManifest.php`: lector del manifest de excepciones.
- `config/company-scope-exceptions.php`: el manifest mismo: única vía sancionada para silenciar un hallazgo `missing_scope`/`not_company_scoped`, por FQCN exacto (sin exclusiones por namespace/regex/nombre corto).

**Auto-discovery real**: sin `--plugins`, el script audita `plugins/webkul/*/src/Models` completo (26 plugins detectados en disco, orden determinista, excluye silenciosamente los que no tienen `src/Models` como `barcode`/`full-calendar`): ya no un default hardcodeado de 3 plugins. `php scripts/audit-company-scope.php` sin argumentos es ahora una auditoría global real, no un falso verde parcial.

**Estados por fila**: `effective_status` colapsa el escaneo crudo + la clasificación del manifest en `scoped | classified_exception | real_gap_company_column | real_gap_without_company_column | table_missing | inspection_error`. `Auditor::isRealGap()` cubre AMBOS `missing_scope` (tiene `company_id`, sin `HasCompanyScope`) y `not_company_scoped` (sin `company_id`) cuando no hay una entrada de manifest válida: antes solo el primero contaba para `--fail-on-missing`, dejando huecos como `Milestone`/pivotes/children sin `company_id` fuera del gate.

El auditor valida el manifest completo en cada corrida (independiente del `--plugins` solicitado) y falla (`exit 2`) ante cualquiera de:

- `invalid_shape`: falta `table`/`classification`/`reason`/`tracking` no-vacíos, o `classification: alias` sin `alias_of`.
- `class_not_found`: la clase de una entrada ya no existe/autoload.
- `table_mismatch`: la tabla registrada no coincide con la tabla real del modelo.
- `invalid_classification`: la clasificación no es una de `global_party_identity|alias|global_reference|parent_scoped|not_tenancy|root_company_entity|multi_company_membership`.
- `stale_exception`: el modelo ya usa `HasCompanyScope` de verdad (basta `uses_company_scope === true`, sin importar `has_company_id`); la excepción sobra y debe eliminarse.
- `alias_chain_broken`: el `alias_of` no es realmente ancestro (`is_subclass_of`), o apunta a una clase sin entrada de manifest y no autoloadable.
- `alias_chain_cycle`: la cadena de `alias_of` se revisita a sí misma.
- `table_missing` / `inspection_error` (comportamiento preexistente).

Un gap real (sin entrada de manifest, `missing_scope` o `not_company_scoped`) solo hace fallar la corrida (`exit 1`) cuando se pasa `--fail-on-missing`: es trabajo pendiente conocido, no un auditor roto. Verificado explícitamente sobre la base fresh: `php scripts/audit-company-scope.php --format=json` → `exit 0` (0 table_missing, 0 inspection_errors, 0 manifest_violations, 78 gaps reales); el mismo comando `--fail-on-missing` → `exit 1` (78 gaps reales > 0).

Cobertura de tests: `tests/Feature/Support/CompanyScopeAuditorTest.php` (29 casos): excepción válida, alias esperado, cadena de alias multi-hop real, `not_company_scoped` clasificado vs no clasificado, `missing_scope` no clasificado, `table_missing`, excepción stale (por `uses_company_scope` solo), tabla incorrecta, clasificación inválida, clase inexistente, shape inválido (4 variantes: cada campo requerido eliminado por completo), validación detenida tras shape inválido (sin segunda violación espuria), alias sin `alias_of`, cadena de alias rota (clase inexistente Y clase real-pero-no-registrada, casos distintos), ciclo de alias, manifest válido con tabla físicamente ausente en la corrida (sin violación), TableView/TableViewFavorite no silenciables vía manifest, gap sin company_id visible en `--format=table`, excepción sin company_id oculta en `--format=table`, `table_missing` siempre visible, discovery global determinista, `--plugins` focalizado, plugin inexistente solicitado.

## Checkpoint de esquema completo (fresh install aislado)

Base aislada `db_aureuserp_audit_fresh` (mismo servidor MySQL del entorno dev, base y usuario separados de `db_aureuserp`/`db_aureuserp_test`). Secuencia: `erp:install --force -n` + los 20 comandos `<plugin>:install -n` (accounting, accounts, barcode, blogs, contacts, employees, full-calendar, inventories, invoices, maintenance, manufacturing, payments, products, projects, purchases, recruitments, sales, time-off, timesheets, website): todos exit 0.

Corrida final tras ola 4A, `php scripts/audit-company-scope.php --format=json` (auto-discovery, 26 plugins, sin `--plugins`) sobre la misma base fresh, committeada en `docs/security/company-scope-pr4-inventory.json`:

```json
{
  "total": 304,
  "scoped": 112,
  "classified_exceptions": 123,
  "real_gaps_with_company_id": 38,
  "real_gaps_without_company_id": 31,
  "table_missing": 0,
  "inspection_errors": 0,
  "manifest_violations": 0
}
```

`exit 0` sin `--fail-on-missing`; `exit 1` con `--fail-on-missing` (69 gaps reales = 38 + 31 > 0, todavía esperado: quedan olas futuras): verificado explícitamente, no asumido.

| Corrida | table_missing | inspection_error | manifest_violations | gaps reales | exit (sin flag) | exit (`--fail-on-missing`) |
|---|---|---|---|---|---|---|
| DB dev existente (`db_aureuserp`), auto-discovery | 35 | 0 | 0 | n/a (no se completó, table_missing>0 es fatal) | 2 | 2 |
| DB aislada, fresh install completo (checkpoint, antes de ola 4A) | **0** | **0** | **0** | 78 | **0** | **1** |
| DB aislada, fresh install completo (ola 4A, ronda 1) | **0** | **0** | **0** | 70 | **0** | **1** |
| DB aislada, fresh install completo (ola 4A, ronda 2) | **0** | **0** | **0** | 69 | **0** | **1** |

`scoped` sube de 106 a 112 (+6: `Project`, `Task`, `TaskStage`, ambas clases `Timesheet`: `Webkul\Project\Models\Timesheet` y `Webkul\Timesheet\Models\Timesheet`: y, desde la ronda 2, `Webkul\Analytic\Models\Record`, el owner físico de `analytic_records`). `classified_exceptions` sube de 120 a 123 (+3: `TableView`, `TableViewFavorite`, `Milestone`). Gaps reales bajan de 78 a 69.

## Manifest de excepciones: resumen

123 entradas, 0 violaciones (shape, tabla, clasificación, stale, cadena de alias) sobre el manifest completo. `TableView`/`TableViewFavorite` reingresaron al manifest en ola 4A tras cerrar el IDOR con un resolver server-side real (ver "Ola 4A" abajo); `Milestone` entró por primera vez, `parent_scoped` vía su Project obligatorio.

Conteo exacto (`config/company-scope-exceptions.php`, verificado por script, no a mano):

| Clasificación | Cantidad | Notas |
|---|---|---|
| `global_party_identity` | 1 | `Webkul\Partner\Models\Partner` (raíz canónica) |
| `alias` | 45 | Partner/Customer/Vendor/Address (15 aliases del grupo original) + Currency/Bank/Industry/Tag/Title/Incoterm/CashRounding/Category/Attribute/ProcurementGroup/UTMMedium/UTMSource/EmploymentType/SkillType/`Company`(security): cadenas multi-hop verificadas (p.ej. `sales.Category → invoices.Category → accounts.Category → products.Category`) |
| `global_reference` | 38 | Currency, Country, State, Bank, UOM, UOMCategory, EmailTemplate, custom_fields, UTMMedium/Source/Stage, products Attribute/Category/AttributeOption/Tag, accounts Incoterm/CashRounding/Tag/PaymentMethod, inventories Tag, manufacturing WorkCenterLossType/Tag/ProductivityLoss, HR/recruitment lookup tables (EmployeeCategory, DepartureReason, EmployeeResumeLineType, EmploymentType, SkillType, SkillLevel, Skill, ApplicantCategory, Degree, RefuseReason), `maintenance.Stage` (contrato aprobado), `partners`/`contacts` Industry/Tag/Title, `projects.Tag` |
| `parent_scoped` | 25 | Pivotes/hijos de un parent YA `HasCompanyScope` o pivote-validado, con evidencia citada (p.ej. `sales.OrderLine` vía `ValidatesRelatedCompanyScope`, `accounts_account_*` pivots vía Journal/Tax/Move ya escopados, `manufacturing_work_orders/operations/work_center_capacities`, y desde ola 4A `Webkul\Project\Models\Milestone` vía su Project mandatorio) |
| `not_tenancy` | 12 | Permission, Role, Team(security), Plugin, EmailLog, Blog Category/Post/Tag, Website Page, ActivityTypeSuggestion, y desde ola 4A `TableView`/`TableViewFavorite` (estado de UI propio del usuario, IDOR cerrado vía resolver) |
| `root_company_entity` | 1 | `Webkul\Support\Models\Company` |
| `multi_company_membership` | 1 | `Webkul\Security\Models\User` |
| **Total** | **123** | |

`BankAccount` (4 clases) queda deliberadamente **fuera** del manifest: es un gap real pendiente del pivote `partners_bank_account_companies` del contrato aprobado, no una excepción. Ver sección 4.

Todos los modelos hijos/pivote de un parent que **todavía no está escopado** (Employee, JobPosition, Department, Candidate, Applicant, Calendar, ActivityPlan, LeaveType, Team de maintenance/sales) quedan deliberadamente fuera del manifest también: `parent_scoped` solo se usa cuando existe enforcement real y citable, nunca por adelantado. `Project`/`Task`/`TaskStage`/`Timesheet` ya no aparecen en esta lista de pendientes: desde ola 4A son `scoped` de verdad (`HasCompanyScope` + enforcement real en cada save), no manifest: ver "Ola 4A" abajo.

---

## Leyenda de clasificación (matriz narrativa, secciones 0-6)

- `owner standalone`: HasCompanyScope, company_id no-nulo, autorización de escritura en cada save, company_id inmutable.
- `child`: deriva compañía del parent persistido, sin columna propia o validada contra el parent.
- `global_party_identity`: Partner/Customer/Vendor y alias, decisión cerrada, NO tocar.
- `global_system_config` / `global_reference_data`: dato compartido por diseño (países, monedas, UOM, bancos, permisos, plantillas de email...), sin dimensión de compañía.
- `relational-no-company-with-pivot`: sin company_id, validado vía pivote M2M a Company.
- `multi_company_membership`: User (default_company_id + user_allowed_companies), es la raíz que CompanyScope::allowedCompanyIds() lee.
- `root_company_entity`: Company mismo, no se auto-escopa.
- **real gap**: requiere código de negocio, autorizado a landear en esta misma rama/PR #18 una vez el checkpoint quede aprobado (no en un PR separado).

---

## 0. Dominios ya cerrados (PR #17): verificación, sin acción

71 filas dedupe a 45 tablas físicas. Todas resuelven a 4 patrones ya revisados:
1. Taxonomía/referencia global (products_attributes, products_categories, currencies, accounts_incoterms, accounts_cash_roundings, accounts_account_tags, manufacturing_work_center_loss_types/tags/productivity_losses, inventories_tags). `partners_bank_accounts` fue removida de esta lista: ver sección 4, es un gap real de PR 4, no una excepción cerrada.
2. Pivotes/hijos cuyo único FK apunta a un parent ya `HasCompanyScope` (accounts_account_* pivots, inventories_package_destinations, inventories_procurement_groups, purchases_order_groups, manufacturing_work_orders/work_center_capacities/operations: estos 3 últimos con comentario explícito "#138 review round 2, 2026-07-18" en código).
3. `accounts_accounts` (Account): sin company_id propio, validado vía pivote M2M `accounts_account_companies` (confirmado en `accounts/src/Models/Account.php:111,200`).
4. Los 9 `missing_scope` de Partner/Customer/Vendor documentados en PR #17: ahora formalizados en el manifest (junto con 7 aliases adicionales de otros plugins no cubiertos por esa auditoría más angosta, ver arriba).

Los 13 `table_missing` de `manufacturing` en la corrida sobre `db_aureuserp` eran solo entorno (confirmado por el checkpoint de fresh install: sus tablas existen y ya tienen `HasCompanyScope` en código, con comentario de ronda de revisión).

Dos notas menores no bloqueantes: `inventories.PackageDestination`/`ProductQuantityRelocation` no tienen consumidores en todo el código (posibles modelos huérfanos); `inventories_tags` no está nombrada explícitamente en la sección de `inventories` del plan doc (`docs/plans/2026-07-07-company-scope-rollout.md`), aunque el patrón es idéntico a otros 4 Tag ya aceptados.

**Veredicto: nada requiere código de negocio para este bloque.**

---

## 1. Cluster HR: employees, time-off, recruitments (49 filas)

Tablas compartidas cruzando plugins (un solo owner físico, resto son alias sin lógica):
- `activity_plans`/`activity_types` → owner real: **support** (`Webkul\Support\Models\ActivityPlan/ActivityType`)
- `calendars`/`calendar_attendances`/`calendar_leaves` → owner real: **support** (renombradas desde `employees_*` en migración `2026_04_02_000001`)
- `utm_mediums`/`utm_sources` → owner real: **support**
- `employees_job_positions`/`employees_departments`/`employees_employment_types`/`employees_skill_types` → owner real: **employees** (recruitments solo ALTERs + subclases)

### Hallazgo más severo del cluster (CRÍTICO): contrato aprobado, ver sección "Decisiones de contrato"

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
| `Employee\WorkLocation` | employees_work_locations | Alto | company_id NOT NULL en DB pero sin autorización: Select abierto sin restricción |
| `Recruitment\Applicant` | recruitments_applicants | Alto | HasCompanyScope + validar candidate_id/job_id/department_id; además bug de clave duplicada en `createEmployee()` descarta el company_id propio |
| `Recruitment\Candidate` | recruitments_candidates | Alto | HasCompanyScope (mismo patrón de auto-provisión de Partner que Employee) |
| `TimeOff\LeaveAccrualPlan` | time_off_leave_accrual_plans | Alto | HasCompanyScope; ojo: `onDelete('cascade')` en company_id (anómalo, borra planes si se borra la Company) |
| `TimeOff\LeaveMandatoryDay` | time_off_leave_mandatory_days | Alto | HasCompanyScope |
| `TimeOff\LeaveType` | time_off_leave_types | Alto | HasCompanyScope; decidir strict vs shared (tipos como "Enfermedad" pueden ser globales) |
| `Recruitment\Stage` | recruitments_stages | Bajo-Medio | Decisión de producto: global vs por-compañía (hoy sin company_id) |

### Bypass patterns
`Company::first()` en 4 seeders (DepartmentSeeder, WorkLocationSeeder, AccrualPlanSeeder, LeaveTypeSeeder). Ver "Seeders e instaladores" en Decisiones de contrato: esto ya NO se considera un bypass a corregir por seeder individual, ver contrato aprobado.

### Bugs no relacionados a scope (flag, no bloqueante)
- `Applicant::createEmployee()`: clave de array duplicada `'company_id'`, se sobrescribe silenciosamente con `candidate.company_id`.
- `LeaveAllocation::creator()` apunta a columna `user_id` que no existe en la tabla.

---

## 2. Cluster PM/Analytics: projects, timesheets, analytics (10 filas)

`analytic_records` es tabla física única con herencia STI: `Analytic\Record` (base, owner real) ← `Project\Timesheet extends Record` ← `Timesheet\Timesheet extends Project\Timesheet` (subclase vacía). Un solo fix en `Record::boot()` resuelve las 3 clases.

### Hallazgos críticos: contrato aprobado, ver sección "Decisiones de contrato"

- **`Project` no tiene CompanyScope** (solo `UserPermissionScope`, que es visibilidad usuario/equipo, no aislamiento de tenant).
- **IDOR confirmado en `MilestoneController`**: `show/update/destroy` hacen `Milestone::findOrFail($id)` sin ningún chequeo de compañía/pertenencia; la policy solo valida una ability de Spatie, nunca ownership por registro.
- **Timesheet/`analytic_records` nunca recibe company_id al crear**: el único lugar que lo backfilla es el hook `updated()` de `Task`.
- `Task.company_id` se deriva de `Auth::user()->default_company_id`, nunca del `project_id` ya seleccionado en el mismo formulario.

### Prioridad de fix (orden del propio agente, confirmado en la revisión)
1. `Project` (raíz): 2. Cadena Timesheet: 3. `Milestone` (IDOR): 4. `Task`/`TaskStage`: 5. `ProjectStage` (`IncludesSharedCompanyRows`): 6. `ActivityPlan` (fix pertenece a Support): 7. `Tag` (sin acción).

**✅ Resuelto en ola 4A** (puntos 1-4 de esta lista): `Project`/`Task`/`TaskStage`/`Timesheet` (ambas clases) y `Milestone`: ver sección "Ola 4A" al final del documento. `ProjectStage` y `ActivityPlan` siguen pendientes de una ola futura.

---

## 3. Cluster Platform: chatter, security, support (30 filas)

### Hallazgos clave

1. **`Security\Models\Company` es código muerto**: subclase vacía de 1 línea, cero referencias fuera de su propia policy huérfana. La clase canónica real es `Support\Models\Company` (228 usos).
2. **`Support\Models\Company` (la real) correctamente no se auto-escopa**: entidad raíz de tenant, protegida por permisos Filament-Shield.
3. **`User` es la raíz de `multi_company_membership`** (`default_company_id` + pivote `user_allowed_companies`). Sin gap propio.
4. **Gap real en flujo de invitaciones: contrato aprobado**, ver "Decisiones de contrato".
5. **`Chatter\Attachment.company_id` es columna muerta**: existe pero ningún call-site la puebla.
6. **`CurrencyRate`**: candidato claro a `IncludesSharedCompanyRows`.
7. **`UtmCampaign`** es la única del grupo UTM que debería escoparse pero no lo hace.

### Clasificación global_system_config (sin acción)
Permission, Role (catálogo RBAC), Bank, Country, State, Currency, UOM, UOMCategory, EmailTemplate, UTMMedium, UTMSource.

### Gaps reales a escopar (código de negocio, esta misma rama/PR #18)
ActivityPlan/ActivityPlanTemplate (ver HR), Calendar, CalendarLeave (ver HR), CurrencyRate, UtmCampaign, Chatter Message/Attachment, Invitation (contrato aprobado).

---

## 4. Cluster Identity: partners, contacts (14 filas)

`partners` = capa de modelo/schema (dueña de TODAS las migraciones); `contacts` = capa de UI (subclases de 0 lógica). Ambos plugins son necesarios, no hay duplicado a resolver.

- `Partner`/`Address`/Customer/Vendor → 16 excepciones en el manifest: 1 `global_party_identity` (`Webkul\Partner\Models\Partner`, raíz canónica) + 15 `alias` (Address, y las plugin-scoped subclasses de Partner/Customer/Vendor).
- `Bank`, `Industry`, `Tag`, `Title` → clasificadas `global_reference` en el manifest de este checkpoint (sin company_id por diseño, sin FK a una entidad tenant-owned).
- **`BankAccount`** (`partners_bank_accounts`, 4 clases) → **gap real, deliberadamente fuera del manifest**. Contrato aprobado con pivote `partners_bank_account_companies`, ver "Decisiones de contrato": no implementado en este checkpoint.

---

## 5. Cluster Commerce: sales, maintenance, payments (22 filas)

- **`maintenance`**: contrato aprobado, ver "Decisiones de contrato": `Stage` queda referencia global; `Team`/`EquipmentCategory`/`Equipment`/`MaintenanceRequest` quedan `strict_company`.
- **`payments`** (PaymentToken/PaymentTransaction): dormido, sin write path actual. Escopar como prerequisito bloqueante antes de conectar cualquier gateway real.
- **`sales.Team`**: mismo gap vivo que `maintenance.Team`.
- **Bypass explícito**: `OrderTemplateProduct::boot()` usa `Company::first()?->id`/`Product::first()?->id`/`UOM::first()?->id`: dormido (Filament Resource huérfano), pero mismo anti-patrón que este rollout cierra en otros lados.
- `OrderLine` (sales) ya correctamente implementado (child + `ValidatesRelatedCompanyScope`): falso positivo del auditor, sin acción.

---

## 6. Cluster Infra: blogs, website, table-views, fields, plugin-manager (9 filas)

| Modelo | Tabla | company_id | Clasificación | Acción |
|---|---|---|---|---|
| `Blog\Category/Post/Tag` | blogs_categories/posts/tags | no | Contenido de sitio único, global por diseño | Ninguna |
| `Website\Page` | website_pages | no | Igual que Blog | Ninguna |
| `Website\Partner` | partners_partners | n/a (alias) | `global_party_identity`, excepción de portal Customer (ADR 0007) | Ninguna: decisión cerrada |
| `Field` (fields) | custom_fields | no | Definiciones de campo custom (schema), `global_system_config` | Ninguna |
| `Plugin` (plugin-manager) | plugins | no | Registro de instalación a nivel de sistema | Ninguna |
| `TableView`/`TableViewFavorite` | table_views/table_view_favorites | no | User-owned, lectura pública opcional | ✅ Resuelto en ola 4A |

### IDOR confirmado en TableView: contrato aprobado, ver "Decisiones de contrato"

`HasTableViews::getSavedTableViews()` no filtra por compañía. Más serio: `EditViewAction`/`deleteTableViewAction`/`replaceTableViewAction` resolvían `TableView::find($arguments['view_key'])` sin re-verificar `user_id` server-side: el chequeo de propiedad solo gateaba visibilidad del botón.

**✅ Resuelto en ola 4A**: ver sección "Ola 4A" al final del documento.

---

## Resumen de bypass patterns encontrados (todos los clusters)

- `Company::first()` / `Company::query()->value('id')` en 6+ seeders y en runtime real (`sales/OrderTemplateProduct.php:57`, dormido).
- `Auth::user()->default_company_id` usado en vez de un parent ya disponible: `TimeOff\Leave`, `TimeOff\LeaveAllocation`, `Project\Task`: el hallazgo más repetido y severo.
- Setting global único (`UserSettings::default_company_id`) determinando la compañía de usuarios recién invitados.
- Ningún seeder en todo el alcance de PR 4 usa `CompanyContext::runForX()` directamente: ver contrato aprobado sobre seeders/instaladores (NO es un defecto a corregir per-seeder).
- Dos IDOR confirmados por falta de autorización de propiedad/relación: `MilestoneController` (projects) y `EditViewAction`/`deleteTableViewAction` (table-views).

---

## Decisiones de contrato aprobadas (revisión #138, comentario `5016816710`): pendientes de implementación

Estas decisiones quedaron cerradas en la revisión de este checkpoint. **Ningún código de negocio fue tocado todavía**: quedan como contrato autorizado para implementarse en esta misma rama (`feat/company-scope-remaining-plugins`, PR #18) una vez el checkpoint quede aprobado. Un PR adicional para PR 4 permanece prohibido.

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

`BankAccount` permanece hijo de `global_party_identity`: no recibe `HasCompanyScope` ni `company_id` strict. Se agrega tabla de membresía:

```
partners_bank_account_companies
- bank_account_id
- company_id
- unique(bank_account_id, company_id)
```

Reglas: creación desde una compañía habilita esa membresía; `PaymentRegister` exige BankAccount habilitada para su compañía persistida; `partner_bank_id` debe pertenecer a `partner_id`; `Employee.bank_account_id` debe estar habilitada para `Employee.company_id`; consultas tenant-facing filtran por el pivote; procesos sin actor requieren compañía/contexto explícito.

Backfill determinista desde `Employee.company_id + bank_account_id`, `PaymentRegister.company_id + partner_bank_id` y otros owners tenant identificados en esta matriz. Una fila no utilizada o ambigua queda sin membresía (inaccesible hasta remediación explícita): nunca usar `Partner.company_id`, `creator.default_company_id` ni `Company::first()` como fallback.

### Seeders e instaladores

**Corrección respecto al hallazgo inicial**: no es necesario que cada seeder invoque `CompanyContext::runForX()` individualmente. `InstallCommand::handle()` (`plugins/webkul/plugin-manager/src/Console/Commands/InstallCommand.php:57-82`) ya envuelve migraciones y seeders completos en `CompanyContext::runForBootstrap()`, y reutiliza el contexto ya abierto cuando instala dependencias recursivamente (evita nesting prohibido).

```
seeders llamados por plugin:install → context-neutral, heredan bootstrap
seeder/command/job ejecutable standalone → abre CompanyContext en su boundary
factory → no abre contextos por sí sola
```

Envolver seeders individuales en `runForBootstrap()` produciría reentrancia prohibida, así que se evita.

---

## Correcciones de la segunda revisión (#138, revisión `4731774794`)

1. **`TableView`/`TableViewFavorite` retiradas del manifest.** Clasificarlas silenciaba el IDOR confirmado (ownership check pendiente en `EditViewAction`/`deleteTableViewAction`/`replaceTableViewAction`): un contrato aprobado pero no implementado no puede desaparecer del conteo de gaps solo por declarar una clasificación. Ambas vuelven a `real_gap_without_company_column`. Test dedicado: `it('does not let a manifest exception silence a model with an approved-but-unimplemented contract (TableView)')`.
2. **Validación de manifest separada en estática vs física.** `validateManifest()` ya no llama a `Schema::hasTable()`/`hasColumn()`: solo reflection pura (`class_exists`, `getTable()`, `class_uses_recursive`, `is_subclass_of`). Una corrida `--plugins=A` con una entrada de manifest para un modelo del plugin B, cuya tabla no está migrada en ese esquema, ya no produce `manifest_violation`. `table_missing` sigue siendo exclusivamente responsabilidad de `inspectClass()`, acotado a lo realmente inspeccionado. Test: `it('does not require a manifest entry\'s table to physically exist...')`.
3. **La validación se detiene tras `invalid_shape`.** Una entrada con shape inválido (clave faltante) ya no cae en `validateEntryAgainstReflection()`, que asumía la forma válida. 4 tests nuevos eliminan por completo cada una de `table`/`classification`/`reason`/`tracking` y confirman que solo se reporta `invalid_shape`, sin una segunda violación espuria.
4. **Cadena de alias exige terminal registrado.** `validateAliasChain()` ya no acepta como válido un `alias_of` que apunte a una clase real, autoloadable y de la misma tabla si esa clase no tiene su propia entrada en el manifest: ahora es siempre `alias_chain_broken`. Test dedicado distingue este caso del de "clase totalmente inexistente".
5. **Salida `--format=table` ya no oculta gaps sin `company_id`.** Nuevo `Auditor::shouldDisplayInTable()`: visible si tiene `company_id`, o si es cualquier `real_gap_*`, o si es `table_missing`/`inspection_error`; solo se ocultan por defecto las excepciones clasificadas sin `company_id` (referencia global, alias, not_tenancy). Verificado con `--plugins=table-views`: ambas filas aparecen ahora como `real_gap_without_company_column`.
6. **CI corregido y con verificación de drift exacta.** El job `Company Scope Global Audit` fallaba en `Running Composer Install` porque `filament:upgrade` (hook `post-autoload-dump`) intenta copiar assets de `resources/dist/` que solo existen tras el job `frontend_assets`: faltaban `needs: frontend_assets` y el step `Download built plugin assets`, ya agregados. El gate de `total >= 300` fue reemplazado por un `diff -u` exacto entre el JSON recién generado y `docs/security/company-scope-pr4-inventory.json` committeado: cualquier drift (modelo escopado, excepción agregada/quitada, tabla renombrada) rompe el CI hasta que el inventario se regenere y se commitee en el mismo cambio.

Números actualizados tras estas correcciones (120 excepciones, no 122: las 2 de TableView/TableViewFavorite salieron del manifest): ver el bloque JSON al inicio de este documento.

---

## Ola 4A: TableView/TableViewFavorite, Project, Task, TaskStage, Milestone, Timesheet

Autorizada tras el checkpoint aprobado (revisión `4736684439` de PR #18, mismo head `1ded2ae96`). Implementa los contratos de negocio descritos en "Decisiones de contrato aprobadas" para Table Views y Projects (secciones "Projects" y "Table Views" arriba), landeando en la misma rama/PR #18.

### TableView / TableViewFavorite

IDOR cerrado con un único resolver server-side reutilizado por editar, reemplazar y eliminar:

```php
TableView::resolveOwnedTableViewOrFail(int $viewId, string $filterableType, int $userId): TableView
```

Una sola consulta exige simultáneamente `id`, `filterable_type` y `user_id` del actor: una vista pública ajena nunca resuelve por esta vía (solo lectura, vía `getSavedTableViews()` que ya filtraba propio+público). `TableView::assertVisibleOrFail()` cubre el caso de lectura/favorito (propio o público). `TableViewFavorite::toggleForOwnViewOrFail()` fuerza siempre `user_id = Auth::id()` (nunca un valor de la request) y rechaza favoritear una vista privada ajena. Wired en `HasTableViews::deleteTableViewAction/replaceTableViewAction/add|removeTableViewToFavoritesAction` y en `EditViewAction::fillForm()/action()`. 11 tests en `plugins/webkul/table-views/tests/Feature/TableViewOwnershipTest.php`. Ambos modelos reingresan al manifest como `not_tenancy` (nunca tendrán `company_id`: es aislamiento por usuario, no por tenant) con la evidencia del resolver citada en `config/company-scope-exceptions.php`.

### Project

`HasCompanyScope` + `HasStrictCompanyId` (mismo patrón que `Journal`/`PaymentTerm`/`FiscalPosition`): `company_id` obligatorio (se autocompleta desde `default_company_id` del actor solo si viene vacío: nunca sobrescribe un valor explícito no autorizado), autorización en cada save vía `CompanyScope::assertCanWriteCompany()`, inmutable tras creación ("archive and recreate"). Fail-closed sin usuario/contexto. 10 tests en `ProjectCompanyScopeTest.php`.

### Task / TaskStage

`company_id` se deriva del `Project` persistido (nunca de una relación en memoria) vía `ValidatesRelatedCompanyScope::resolveEffectiveCompanyIdOrFail()`, ya usado en el dominio de accounts (PR #17) para el mismo patrón madre→hijo. Un `project_id` inexistente o cuyo Project no tiene compañía falla cerrado. Un cambio de `project_id` (solo, o junto con un `company_id` explícito y consistente) que implique traslado de tenant se rechaza: comparando el `company_id` efectivo recién resuelto contra `getOriginal('company_id')`, el mismo mecanismo de inmutabilidad que `Project`, pero derivado en vez de directo. `Task` tolera `project_id = null` (columna nullable, `nullOnDelete`: una tarea huérfana tras borrar su Project) reautorizando su propio `company_id` ya persistido en vez de fallar en cada save futuro; `TaskStage.project_id` es NOT NULL (`cascadeOnDelete`), así que siempre deriva. 12 + 7 tests (`TaskCompanyScopeTest.php`, `TaskStageCompanyScopeTest.php`).

### Milestone

Sin columna `company_id` propia (su Project padre es obligatorio, `cascadeOnDelete`). Lectura: `Milestone::booted()` agrega un global scope `whereHas('project')`: el scope `HasCompanyScope` de `Project` se aplica automáticamente dentro de esa subquery, así que un Milestone cuyo Project está oculto (compañía equivocada, o sin usuario/contexto: `CompanyScope` falla cerrado) también queda oculto. Escritura: `resolveEffectiveCompanyIdOrFail()` sobre el `project_id`, descartando el valor de retorno (no hay columna donde persistirlo): el efecto es puramente de autorización: Project debe existir, tener compañía propia, y el actor debe estar autorizado para escribir en ella. `MilestonePolicy::view/update/delete` ahora también re-derivan la compañía efectiva desde el Project persistido (`belongsToAllowedCompany()`) e la comparan contra las compañías permitidas del actor: una ability genérica de Spatie ya no es suficiente por sí sola, cerrando el IDOR también a nivel de policy, no solo de resolver. 8 tests (`MilestoneCompanyScopeTest.php`).

### Timesheet (`Webkul\Project\Models\Timesheet` y su subclase vacía `Webkul\Timesheet\Models\Timesheet`)

Valida el grafo completo Timesheet → Task → Project → company: `company_id` se deriva del `Task` persistido (mismo patrón que Task deriva de Project); si además trae un `project_id` explícito, debe coincidir con el `project_id` del Task referenciado (una Task de Project A con un Timesheet apuntando a Project B es un grafo espurio, rechazado aunque ambos sean de la misma compañía). Reasignar `task_id` a una Task de otra compañía se rechaza igual que Task/TaskStage. `task_id = null` (huérfana tras borrar su Task) reautoriza su propio `company_id`. 8 tests (`TimesheetCompanyScopeTest.php`).

### Regresión de fixtures (no de producción)

`TaskStageFactory`/`TaskFactory` tenían un `'company_id' => Company::factory()` independiente de su `'project_id' => Project::factory()`: dos compañías aleatorias no relacionadas, inofensivo mientras `Project`/`TaskStage` no tenían enforcement real. Con el enforcement de esta ola, esa inconsistencia rompía 16 tests preexistentes de `plugins/webkul/projects/tests/Feature/API/V1/{TaskStageTest,TaskTest}.php`. Corregido quitando el `company_id` independiente de ambas factories (mismo patrón ya usado en `MoveLineFactory` desde PR #17: "dejar sin asignar, el propio saving() hook lo deriva"). Suite completa de `plugins/webkul/projects` tras el fix: 116/116 verde.

### Cobertura total de esta ola

81 tests nuevos entre las dos rondas (`ProjectCompanyScopeTest` 10, `TaskCompanyScopeTest` 23, `TaskStageCompanyScopeTest` 7, `MilestoneCompanyScopeTest` 11, `TimesheetCompanyScopeTest` 11, `TableViewOwnershipTest` 14, `RecordCompanyScopeTest` 5), cubriendo por cada modelo: aislamiento de lectura (actor ve solo su(s) compañía(s)), lista vacía sin compañías, fail-closed sin actor/contexto, creación/actualización cross-company rechazadas, reasignación rechazada (donde aplica), al menos una prueba con `withoutGlobalScope()` demostrando que la autorización de escritura no depende del scope de lectura, y `CompanyContext::runForCompany/runForAllCompanies/runForBootstrap` operando correctamente. `--fail-on-missing` sigue retornando `1` (69 gaps reales restantes: Time Off, Invitations, BankAccount, Maintenance, y otros hijos de parents aún no escopados quedan para olas futuras).

---

## Correcciones de la ronda 2 de ola 4A (#138, revisión `4739111455`)

1. **TableView/TableViewFavorite ya no aceptan un `$userId` como argumento.** `resolveOwnedTableViewOrFail()`, `assertVisibleOrFail()` y `toggleForOwnViewOrFail()` derivan el owner de `Auth::id()` internamente: un usuario autenticado ya no puede pasar el id de otro y que se confíe como dueño. Ambos modelos ahora fuerzan `user_id = Auth::id()` en `creating()` y lo hacen inmutable en `saving()`. El cleanup de `deleteTableViewAction` agrega `where('view_type', 'saved')`. Tests: 14/14 en `TableViewOwnershipTest.php` (3 nuevos: fail-closed sin auth, forced-on-create, inmutabilidad x2).
2. **Milestone ya no puede retargetearse desde una compañía oculta.** El `saving()` hook ahora reconsulta el Milestone *persistido* (sin el scope `companyViaProject`) para autorizar también su Project **original**, no solo el nuevo: cerrando el ataque "obtener vía consulta sin scope + reasignar a mi propia compañía autorizada". `MilestonePolicy::belongsToAllowedCompany()` reconsulta el Milestone por PK en vez de confiar en `$milestone->project_id` (que puede estar sucio en memoria). 3 tests nuevos: retargeting B→A rechazado, policy retorna `false` con `project_id` falsificado en memoria, reasignación A1→A2 dentro de la misma compañía permitida.
3. **El owner físico de `analytic_records` (`Webkul\Analytic\Models\Record`) ahora tiene `HasCompanyScope` y autorización de escritura propias**, no solo sus alias Timesheet: una consulta o escritura directa contra `Record` ya no podía eludir el aislamiento. Resolución de compañía polimórfica: `Record::resolveEffectiveCompanyId()` (genérica) vs `Webkul\Project\Models\Timesheet::resolveEffectiveCompanyId()` (deriva y cruza Task↔Project: verifica `Task.company_id === Project.company_id`, `Timesheet.project_id === Task.project_id`). 5 tests nuevos en `plugins/webkul/analytics/tests/Feature/RecordCompanyScopeTest.php`, incluida una Task con `company_id` corrompido manualmente para probar la validación cruzada.
4. **Task.stage_id y Task.parent_id ahora se validan contra el grafo completo.** `stage_id` debe resolver a un `TaskStage` persistido con el mismo `project_id` y compañía; `parent_id` debe resolver a una Task persistida con el mismo `project_id` y compañía, y nunca a sí misma. `TaskFactory` se corrigió para construir `project_id`/`stage_id` de forma consistente (antes eran dos `Model::factory()` independientes que casi nunca coincidían). 6 tests nuevos.
5. **Create/detach sin Project (Task) o sin Task (Timesheet) ahora se rechaza.** Antes, un `project_id`/`task_id` ausente caía silenciosamente al `company_id` del actor. Ahora solo una fila que **ya estaba huérfana antes de este save** (p.ej. tras un `nullOnDelete` real) puede seguir guardándose sin su padre, reautorizando su propio `company_id` persistido: crear una fila nueva sin padre, o desprender manualmente una existente, se rechaza. 6 tests nuevos.
6. **`TestBootstrapHelper` es determinista y reconoce los 20 plugins.** `ensureERPInstalled()` instala los 20 plugins (incluido `website`, ausente del mapa anterior) de forma incondicional tras `erp:install`, no bajo demanda por archivo de test: el esquema final ya no depende de qué archivo corre primero. Al implementarlo se descubrió que `DatabaseTransactions` ya tiene una transacción abierta en la primera invocación (desde un `beforeEach()`), y que el DDL de `migrate:fresh`/cada `:install` hace auto-commit implícito en MySQL sin que Laravel se entere: desincronizando el conteo de transacciones y provocando que los datos sembrados por los 20 installs se revirtieran al terminar el primer test. Corregido haciendo `commit()` explícito de cualquier transacción abierta antes del bootstrap pesado y reabriendo el mismo número de niveles después. Validado con una corrida completa de la suite (`vendor/bin/pest --exclude-group=gold-standard-dataset`) desde una base limpia: **1639/1639 tests pasan**, sin depender del orden.
7. **Bug preexistente descubierto y corregido en `manufacturing/Warehouse.php` (fuera del área de negocio de ola 4A, autorizado explícitamente al detectarse durante la corrección del punto 6).** `createManufacturingRules()`/`createManufacturingOperationTypes()` consultaban `Location::where('type', PRODUCTION)->first()` sin bypasear `CompanyScope`: como `Location` implementa `IncludesSharedCompanyRows` pero `CompanyScope::apply()` niega **todo** (incluidas las filas compartidas) cuando no hay actor autenticado ni `CompanyContext` activo (ADR 0007), esa consulta retornaba `null` en cualquier test que creara un Warehouse sin un actor/contexto ya establecido: antes invisible porque `manufacturing` nunca se instalaba durante una corrida aislada de `inventories`. Corregido con `Location::withoutGlobalScope(CompanyScope::class)->where(...)`, el mismo patrón ya usado en el resto de esta ola para resolver un padre autoritativo. Verificado con la suite completa de `plugins/webkul/inventories` (552/552) y la suite global (1639/1639).

Números actualizados tras esta ronda (112 `scoped`, no 111: `Record` se suma; 69 gaps reales, no 70): ver el bloque JSON al inicio de este documento.

---

## Correcciones de la ronda 3 de ola 4A (A18-01/A18-02/A18-03: determinismo del harness)

La ronda 2 (punto 6) dejó `TestBootstrapHelper` instalando los 20 plugins de forma incondicional, pero una corrida de la suite completa **dos veces seguidas contra la misma base sin recrearla entre medio** seguía fallando en bloque (`website_pages` no encontrada). Root cause encontrado con un repro directo (dos procesos PHP separados contra la misma base):

`Webkul\PluginManager\Package::isPluginInstalled()` decide si el `ServiceProvider` de un plugin registra sus rutas de migración (`loadMigrationsFrom()`) consultando la tabla `plugins` **en el boot de la aplicación**: antes de que corra cualquier código de `TestBootstrapHelper`. Contra una base nunca usada, la tabla `plugins` todavía no existe al momento del boot, así que la mayoría de plugins no registran sus migraciones todavía y `migrate:fresh` solo ve un subconjunto seguro (core/support). Contra una base que un proceso anterior ya usó, el boot de ESTE proceso ve la tabla `plugins` con todo marcado como instalado (la fila sigue ahí, `migrate:fresh` de este proceso todavía no corrió): así que esta vez sí se registran las migraciones de todos los plugins, y `migrate:fresh` procesa un set mucho más amplio, ordenado por nombre de archivo entre todos los plugins. Ahí aparece un defecto real y preexistente: la migración `2026_04_02_..._create_calendars_table` de `support` está fechada DESPUÉS de varias migraciones de otros plugins que le agregan una foreign key (`employees`'s `2024_12_12_..._create_employees_employees_table`, y por separado `manufacturing`'s `..._create_manufacturing_work_centers_table`: el mismo bug que motivó el fix acotado del punto 7 de la ronda 2, ahora confirmado como una instancia más amplia del mismo problema).

**Decisión explícita: no se renombran/re-fechan migraciones de producción.** Es un cambio de esquema más invasivo y riesgoso que el alcance de este harness: y CI nunca lo sufre, porque cada corrida usa un servicio MySQL efímero nuevo, así que la tabla `plugins` nunca preexiste al boot (siempre reproduce el caso seguro). Es exclusivamente un artefacto de reusar la misma base de MySQL local entre invocaciones separadas de `vendor/bin/pest`.

**Fix aplicado**: `TestBootstrapHelper::assertDatabaseNotAlreadyBootstrapped()`: detecta la condición insegura (tabla `plugins` ya existe con filas `is_installed=true`) **antes** de correr `migrate:fresh`, y falla con un `RuntimeException` claro y accionable en vez de dejar el esquema corrompido a medias. Verificado con el repro original: la excepción se dispara, y la base queda completamente intacta (256 tablas, `website_pages` presente) en vez de caer a 47.

**Regresión nueva**: `plugins/webkul/support/tests/Feature/TestBootstrapHelperDeterminismTest.php`, spawnea el script `plugins/webkul/support/tests/fixtures/run_bootstrap_order.php` como procesos PHP reales vía `Symfony\Component\Process\Process` (mismo patrón que `CompanyScopeAuditorTest.php`'s `runAuditScript()`, necesario porque el bug solo es observable ENTRE procesos, nunca dentro de uno solo):
- *"produces the identical final schema regardless of which plugin triggers the bootstrap first"*: dos procesos separados, cada uno contra una base recién vaciada, disparando el bootstrap desde un plugin distinto (`accounting` vs `website`): mismo conteo final de tablas (>200) en ambos.
- *"fails loud instead of silently corrupting the schema when bootstrapped twice against the same never-recreated database"*: reproduce exactamente el escenario del bug: el segundo proceso, contra la misma base sin recrear, falla con el mensaje exacto del guard, y el esquema del primer proceso permanece intacto.

**Pint aplicado sobre todo el delta PHP** (27 archivos: los 24 de `1ded2ae96..139468817` + los 3 nuevos de esta ronda). 4 archivos tenían sugerencias de estilo sin aplicar (`fully_qualified_strict_types`, `ordered_imports`, `binary_operator_spaces`): incluido `config/company-scope-exceptions.php`, donde el fixer convirtió ~40 referencias `\Fully\Qualified\Class::class` a imports `use` + nombre corto. Verificado exhaustivamente que es puramente cosmético: sintaxis válida, mismo conteo de entradas (123) y misma distribución por clasificación antes/después, `diff -u` contra el JSON committeado sigue siendo byte-a-byte idéntico, y los 32/32 tests del auditor (incluida la prueba de cadena de alias que referencia directamente una de las clases renombradas) pasan sin cambios.

Validación completa de esta ronda: 32/32 auditor + 81/81 company-scope + 2/2 determinismo + **1641/1641** en la suite completa (`vendor/bin/pest --exclude-group=gold-standard-dataset`, base recreada desde cero) + `pint`/`composer validate --strict` limpios.

---

## Ola 4B: Maintenance, ProjectStage, ActivityPlan, Time Off/Leave, Invitations, BankAccount

Checkpoint aprobado (revisión `5016816710`) autorizó implementar código de negocio por olas; ola 4B cubre los 6 dominios listados en la sección "Estado" de ola 4A como pendientes. Ningún modelo fuera de estos 6 se toca en esta ola: Employee, Department, Candidate, Applicant, WorkLocation, Calendar (plano) y Recruitment\Stage quedan para una ola futura pese a aparecer en el inventario.

### Maintenance

```
Stage: referencia global (sin cambio, ya clasificado)
Team: HasCompanyScope + HasStrictCompanyId
EquipmentCategory: HasCompanyScope + HasStrictCompanyId
Equipment: HasCompanyScope + HasStrictCompanyId + valida category_id/maintenance_team_id contra su propia compañía
MaintenanceRequest: HasCompanyScope + HasStrictCompanyId + valida equipment_id/maintenance_team_id/category_id contra su propia compañía
```

La réplica recurrente de `MaintenanceRequest` (creación automática de la siguiente instancia cuando una etapa `done` se alcanza) reautoriza la compañía persistida de forma natural: al ser una fila nueva creada vía `replicate()->save()`, pasa por el mismo `saving()` de `HasStrictCompanyId` que cualquier otra creación, sin código adicional. 36 tests nuevos (`TeamCompanyScopeTest`, `EquipmentCategoryCompanyScopeTest`, `EquipmentCompanyScopeTest`, `MaintenanceRequestCompanyScopeTest`), incluida la reautorización de la réplica bajo un contexto que ya no puede escribir en la compañía persistida.

### ProjectStage

Contrato previo (ola 4A, prioridad 5) señalaba `IncludesSharedCompanyRows`. Implementado siguiendo el patrón exacto de `Route`/`Location`: `HasCompanyScope` + `IncludesSharedCompanyRows` + `guardSharedRowMutation()` (filas `company_id IS NULL`, las 4 etapas por defecto sembradas por `ProjectStageSeeder`, solo mutables por super_admin o proceso de sistema). A diferencia de `Route`, se agrega reautorización en cada `update()` de una fila no compartida (`CompanyScope::assertCanWriteCompany()` sobre el `company_id` original), cerrando la misma clase de IDOR que Milestone/Task en ola 4A: un actor que obtiene una fila cross-company vía consulta sin scope y edita un campo no relacionado debe seguir siendo rechazado. Se detectó que `Route`/`Location` mismos no tienen esta reautorización todavía: riesgo señalado para una ola futura, fuera de alcance de ProjectStage. 9 tests nuevos (`ProjectStageCompanyScopeTest`).

### ActivityPlan (owner físico: Support)

```
Webkul\Support\Models\ActivityPlan (owner real): HasCompanyScope + IncludesSharedCompanyRows + guardSharedRowMutation
Webkul\Employee\Models\ActivityPlan, Recruitment\ActivityPlan, Project\ActivityPlan, Sale\ActivityPlan: alias sin lógica, heredan el scope automáticamente
Webkul\Support\Models\ActivityPlanTemplate: parent-scoped vía plan_id (whereHas + resolveEffectiveCompanyIdOrFail, mismo patrón que Milestone)
Webkul\Support\Models\ActivityType (+ alias Webkul\TimeOff\Models\ActivityType): clasificado global_reference en el manifest, catálogo cross-plugin particionado únicamente por la columna plugin, nunca por compañía
```

Bug encontrado y corregido en el mismo commit: el `creating()` original de `ActivityPlan` solo defaulteaba `company_id` desde el actor sin autorizar un valor explícito distinto, el mismo gap que Project cerró en ola 4A ("un usuario de A conociendo el id de B no basta para crear directamente en B"). 17 tests nuevos entre `ActivityPlanCompanyScopeTest` y `ActivityPlanTemplateCompanyScopeTest`.

### Time Off / Leave

```
Leave: HasCompanyScope, company_id y employee_company_id derivan de Employee.company_id (resolveEffectiveCompanyIdOrFail), manager/first_approver/second_approver/department validados contra la misma compañía
LeaveType: HasCompanyScope + HasStrictCompanyId (strict_company propio, seeders siempre asignan una compañía real, sin evidencia de filas compartidas)
LeaveAccrualPlan: HasCompanyScope + HasStrictCompanyId, time_off_type_id validado contra su propia compañía
LeaveMandatoryDay: HasCompanyScope + HasStrictCompanyId
LeaveAllocation: sin company_id propio por decisión de contrato, employee_company_id es la columna tenant real, scope propio (no HasCompanyScope, que asume la columna se llama company_id) replicando la misma precedencia vía los helpers públicos de CompanyScope
LeaveAccrualLevel: parent-scoped vía accrual_plan_id (mismo patrón Milestone/ActivityPlanTemplate)
UserLeaveType: pivote sin id/timestamps entre User y LeaveType, parent-scoped vía leaveType, además valida que el usuario notificado tenga acceso real a la compañía de ese LeaveType
```

`CalendarLeave` (aliaseado también por `time-off`) queda fuera de esta ola: su owner real es `Support\Calendar` (agenda/asistencia), y `manufacturing\WorkOrder` lo crea directamente, ninguno de los dos autorizado en ola 4B. Se documenta como riesgo para una ola de Calendar futura, no como una omisión.

Bugs preexistentes, no relacionados a company-scope, encontrados y corregidos porque bloqueaban las fixtures de esta ola:
- `DepartmentFactory`: `manager_id => Employee::factory()` creaba un ciclo infinito con `EmployeeFactory`'s `department_id => Department::factory()` (nunca antes ejercitado, ninguna prueba en el repositorio invocaba ninguna de las dos fábricas con sus valores por defecto).
- `EmployeeFactory`: `employee_properties` no es una columna real; `user_id` reusaba el primer usuario existente pese a la restricción UNIQUE de la tabla.
- `EmployeeJobPositionFactory`: `status`/`open_date` no son columnas reales (la real es `is_active`).
- `WorkLocationFactory`: `user_id`/`active` no son columnas reales (`creator_id`/`is_active`), y `location_type` usaba una palabra aleatoria en vez de un valor válido del enum.
- `DepartureReasonFactory`: `sequence` no es columna real (`sort`); `reason_code` es entero, no texto.
- `LeaveFactory`/`LeaveMandatoryDayFactory`: rango de fechas invertido (`'+7 days'` es relativo a *ahora*, no a la fecha de inicio ya aleatoria dentro de una ventana de 30 días).
- `LeaveTypeFactory`: `company_id`/`creator_id` usaban enteros aleatorios sin FK real.

52 tests nuevos entre los 7 modelos.

### Invitaciones

Migración nueva en `user_invitations`: `company_id`, `role_id`, `token`, `invited_by`, `expires_at`, `accepted_at`. Al emitir (`ListUsers::inviteUser`): `company_id`/`invited_by` derivan del actor, `role_id` se elige en el formulario (default configurable), `expires_at` a 7 días. Al aceptar (`AcceptInvitation::create()`): transacción + `lockForUpdate()` de la Invitation + valida no aceptada/no expirada + crea el User con la compañía y el rol capturados + asocia `allowedCompanies` + marca `accepted_at`.

`Invitation` deliberadamente no usa `HasCompanyScope`: la ruta de aceptación es de invitado (`signed` middleware, sin actor autenticado), y el scope automático falla cerrado sin usuario ni contexto, lo que bloquearía a cualquier invitado legítimo. La autorización de esa ruta específica es la URL firmada más las validaciones explícitas de estado (no aceptada, no expirada). Tampoco usa `HasStrictCompanyId` completo: ese trait reautoriza en cada `save()` incluso sin actor, exactamente lo que la propia aceptación de invitado necesita hacer al marcar `accepted_at`. Un `boot()` a medida autoriza `company_id` en creación y en cada actualización autenticada (cerrando el mismo IDOR que HasStrictCompanyId cierra en otros modelos), y omite esa reautorización solo cuando no hay actor ni contexto activo, es decir, exclusivamente en el flujo de aceptación de invitado.

El modelo queda clasificado como gap real en el inventario (`has_company_id=true`, `uses_company_scope=false`): es una decisión consciente, no una omisión. Forzar una clasificación del manifest existente (`alias`, `parent_scoped`, `global_reference`, etc.) no describiría honestamente el mecanismo real. `InvitationFactory`/`InvitationResource` referenciaban columnas inexistentes (`role_id`, `token`, `expires_at`, `invited_by`, `accepted_at`) antes de esta migración: código nunca antes ejercitado, ahora alineado con el esquema real. 10 tests nuevos (`InvitationCompanyScopeTest`), incluidos 3 vía `Livewire::test()` sobre el flujo de aceptación real.

### BankAccount

Contrato aprobado: `BankAccount` permanece hijo de `global_party_identity` (Partner), sin `HasCompanyScope` ni `company_id` estricto. Tabla de membresía nueva `partners_bank_account_companies` (`bank_account_id`, `company_id`, único). Crear una `BankAccount` desde una compañía (actor autenticado o `CompanyContext::runForCompany`) habilita esa membresía automáticamente; `CompanyContext::runForAllCompanies`/bootstrap/sin contexto no habilita ninguna, dejando la fila inaccesible hasta remediación explícita.

Dos mecanismos de validación distintos según el rol del referenciador, mismo patrón ya establecido para `Account`/`accounts_account_companies`:
- `Journal.bank_account_id` (designa su propia cuenta operativa): `BankAccount::ensureEnabledForCompany()` habilita, igual que `Account::ensureEnabledForCompany()` para las cuentas por defecto/suspenso/ganancia/pérdida del mismo Journal.
- `Payment/Move/PaymentRegister.partner_bank_id` y `Employee.bank_account_id` (referencian una cuenta ya vetada de un tercero): `BankAccount::assertEnabledForCompany()` rechaza si no está habilitada; adicionalmente `assertBelongsToPartner()` rechaza si la cuenta no pertenece al partner referenciado.

Backfill determinista vía migración separada, desde `Employee.company_id + bank_account_id` y `PaymentRegister.company_id + partner_bank_id` (los únicos owners tenant con datos históricos identificados en la matriz de auditoría de esta ola; Payment/Move/Journal comparten la misma fila física pero no aportan backfill adicional en el modelo de despliegue fresh-install de este repositorio). Nunca `Partner.company_id`, `creator.default_company_id` ni `Company::first()` como fallback: una fila sin owner identificado queda sin membresía.

Igual que `Invitation`, `BankAccount` queda clasificado como gap real en el inventario: no tiene `company_id` ni usa `HasCompanyScope`, por diseño. La protección real vive en la tabla de membresía y en las validaciones de escritura descritas arriba, no en un mecanismo que el auditor automático reconozca todavía. 11 tests nuevos (`BankAccountCompanyScopeTest`), incluidas pruebas directas de `assertBelongsToPartner()`/`assertEnabledForCompany()` dado que la cadena de fábricas de `Payment` (métodos/líneas de pago) tiene brechas propias preexistentes, no relacionadas a company-scope, nunca antes ejercitadas.

### Inventario tras ola 4B

```
scoped: 112 → 126
classified_exceptions: 123 → 129
gaps reales: 69 (38+31) → 49 (25+24)
```

135 tests de company-scope nuevos entre las 6 familias, verificados también corriendo juntos en el mismo proceso (dos bugs de orden de fixtures encontrados y corregidos: creación de una fila antes de autenticar al actor cuando el modelo ya exige autorización explícita en create).

## Ola 4C: BankAccount, aislamiento de lectura

Checkpoint de planificación read-only (agente Explore, sin cambios de código) mapeó el owner físico y los tres alias de `BankAccount`, confirmando que ninguno de los tres tiene lógica propia ni migración propia, y que ningún Resource/Página Filament sobrescribe `getEloquentQuery()`. Alcance autorizado localmente: cerrar exclusivamente las 4 filas del auditor asociadas a `partners_bank_accounts`. `Invitation` queda fuera del delta de esta ola.

```
Webkul\Partner\Models\BankAccount (owner físico): scope de lectura nuevo, BankAccountCompanyMembershipScope
Webkul\Contact\Models\BankAccount, Webkul\Accounting\Models\BankAccount, Webkul\Invoice\Models\BankAccount: alias sin lógica, heredan el scope por late static binding, verificado empíricamente (ningún boot() propio necesario)
```

`BankAccountCompanyMembershipScope` (nuevo, `plugins/webkul/partners/src/Models/Scopes/`) replica la misma precedencia que `CompanyScope::apply()` (ADR 0007) filtrando por la membresía `enabledCompanies()` en vez de una columna `company_id` (que esta tabla no tiene por diseño): usuario autenticado sin contexto usa `CompanyScope::allowedCompanyIds()`; usuario autenticado con un `CompanyContext` simultáneo lanza `LogicException`; sin usuario y `CompanyContext::COMPANY` filtra por esa compañía exacta; `ALL_COMPANIES`/`BOOTSTRAP` sin filtro; sin usuario ni contexto, vacío (fail-closed).

`BankAccount::forAllCompanies()` agrega el mismo bypass explícito y auditado que `HasCompanyScope::forAllCompanies()` (reimplementado localmente, ya que este modelo no usa ese trait): restringido a `super_admin`, cada llamada queda registrada en el log, retorna la query sin `BankAccountCompanyMembershipScope` únicamente (SoftDeletes y cualquier otro scope se mantienen).

Las tres funciones de integridad ya existentes (`ensureEnabledForCompany()`, `assertEnabledForCompany()`, `assertBelongsToPartner()`) ahora excluyen explícitamente solo `BankAccountCompanyMembershipScope` (`withoutGlobalScope(BankAccountCompanyMembershipScope::class)`), preservando su capacidad de operar sobre una fila que el actor no puede leer directamente: sin este ajuste, el nuevo scope de lectura habría roto silenciosamente su propio propósito (validar/habilitar una cuenta física que, por diseño, aún no es visible para el actor).

`Partner\BankAccount` se clasifica en el manifest como `multi_company_membership` (mismo taxonomy ya usado para `User`, generalizado en el comentario del archivo a "aislado por un pivote de membresía explícito"); los tres alias se clasifican como `alias` apuntando a él. No se tocó `app/Support/CompanyScopeAudit/ExceptionManifest.php`.

28 tests en `BankAccountCompanyScopeTest.php` (17 nuevos sobre los 11 existentes): lectura misma compañía, lectura multi-compañía, oculto para compañía no habilitada, vacío sin compañías/sin actor/sin contexto, `CompanyContext::COMPANY`/`ALL_COMPANIES`/`BOOTSTRAP`, conflicto usuario+contexto (`LogicException`), `forAllCompanies()` para super_admin y su rechazo (403) para un usuario ordinario, herencia del scope verificada directamente en las 3 clases alias, y regresión de las 3 funciones de integridad contra una cuenta oculta para el actor. 4 tests adicionales en un archivo nuevo, `BankAccountReadIntegrationTest.php` (autorizado como archivo de integración separado): la query de `BankAccountResource` no enumera una cuenta de otra compañía, el índice/show de la API REST (`admin/api/v1/partners/{partner}/bank-accounts`) tampoco la enumera ni la revela (404), y `ManageBankAccounts::getTabs()` refleja el conteo scopeado en sus badges.

### Inventario tras ola 4C

```
scoped: 126 (sin cambio, BankAccount se clasifica como excepción, no como scoped, igual que User)
classified_exceptions: 129 → 133
gaps reales: 49 (25+24) → 45 (25+20)
```

21 tests añadidos por la ola 4C (17 en BankAccountCompanyScopeTest.php + 4 en BankAccountReadIntegrationTest.php). 32/32 tests focalizados (47 assertions) entre ambos archivos. Regresión verificada sin cambios: ola 4A 81/81, ola 4B 152/152 (135 originales + 17 nuevos de BankAccount), auditor 32/32, determinismo del harness 2/2.

Quedan 45 gaps reales para olas futuras, incluida `Invitation` (fuera de esta ola: su único read-path es un `findOrFail` de un solo registro vía URL firmada de invitado, sin superficie de enumeración; cerrar su clasificación en el manifest requeriría una categoría de taxonomía nueva en `ExceptionManifest::CLASSIFICATIONS`, código core compartido, no un cambio de un solo plugin, por lo que queda diferida a su propia autorización explícita).

## A4D-0: CurrencyRate, aislamiento company-or-shared

Hotfix de seguridad priorizado por severidad (no un empaquetado por dominio como las olas anteriores): `CurrencyRateController@index` exponía un filtro `filter[company_id]` sin restricción, autorizado solo a nivel de `Currency` (no de `company_id`), y `show`/`update`/`destroy` no comparaban el `company_id` de la fila contra el actor en absoluto. Un actor autenticado con permiso sobre la `Currency` podía leer, editar o borrar la tasa de cambio de cualquier otra compañía por id o por parámetro de filtro. Detalle técnico completo, contrato y conteos en `docs/security/company-scope-pr4-wave-4d-plan.md` (sección `A4D-0`).

`Support\CurrencyRate` ahora usa `HasCompanyScope` + `IncludesSharedCompanyRows` (mismo patrón que `ActivityPlan`/`ProjectStage` en ola 4B, sin migración nueva ya que `company_id` ya existía y ya era nullable). El controller y el request no requirieron ningún cambio: al vivir la autoridad real en el modelo, `CurrencyRate::where(...)->firstOrFail()` ya scopeado hace que una fila ajena simplemente no se encuentre (`404` automático), y el filtro `filter[company_id]` se aplica sobre una query que ya excluye las filas de otras compañías, por lo que nunca puede ampliar la visibilidad.

`boot()` reautoriza `CompanyScope::assertCanWriteCompany()` en `creating`/`updating`/`deleting` para filas con compañía (resolviendo siempre el valor persistido, nunca el mutable en memoria), rechaza incondicionalmente cualquier cambio de `company_id` tras la creación (cubre las 3 transiciones prohibidas: A→B, A→null, null→A, incluso para un `super_admin`), y restringe la mutación de filas compartidas (`company_id IS NULL`) a `super_admin` o a un proceso sin usuario dentro de un `CompanyContext::ALL_COMPANIES`/`BOOTSTRAP` explícito, más estricto que el precedente `ProjectStage`/`ActivityPlan` (que permiten cualquier proceso sin usuario), por instrucción explícita dado que esta tabla es información financiera. Sin `restore()`/`force-delete()`: `currency_rates` no tiene columna `deleted_at` y agregarla habría requerido una migración nueva, fuera de alcance de este hotfix; el contrato se redujo explícitamente a `create`/`update`/`delete` (borrado definitivo).

33 tests nuevos (21 en `CurrencyRateCompanyScopeTest.php` a nivel de modelo, 12 en `CurrencyRateApiCompanyScopeTest.php` a nivel de API). El archivo preexistente `CurrencyRateTest.php` (11 tests) requirió ajustar sus fixtures: creaban filas `company_id: null` como un actor autenticado no-`super_admin`, incompatible con el nuevo guard de filas compartidas; corregido usando el patrón ya establecido de auto-grant de compañías nuevas tras autenticar (mismo mecanismo documentado desde ola 4C), sin cambiar ninguna aserción de negocio.

### Inventario tras A4D-0

```
scoped: 126 → 127 (CurrencyRate usa HasCompanyScope real, no se clasificó como excepción)
classified_exceptions: 133 (sin cambio)
gaps reales: 45 (25+20) → 44 (24+20)
```

Regresión verificada sin cambios: ola 4A 81/81, ola 4B 152/152, ola 4C 32/32 (focalizado), auditor 32/32, determinismo del harness 2/2, `ActivityPlanCompanyScopeTest`/`ProjectStageCompanyScopeTest` (precedente `company_or_shared`) sin cambios.

## A4D: familia employees, aislamiento completo

Cierra ocho filas del inventario asociadas a `Webkul\Employee\Models\Employee`, `Department`, `EmployeeJobPosition`, `WorkLocation` (owners) y `Webkul\Recruitment\Models\Department`, `JobPosition`, `JobByPosition` (alias heredados por late static binding) más `EmployeeSkill` (parent-scoped). Detalle técnico completo, matriz de relaciones y contrato por modelo en `docs/security/company-scope-pr4-wave-4d-plan.md`.

Los cuatro owners usan `HasCompanyScope` + `HasStrictCompanyId` (mismo patrón que toda la familia desde ola 4A). Además de la inmutabilidad de `company_id` y la reautorización en cada update que `HasStrictCompanyId` ya provee, se agregó un concern local nuevo, `GuardsCompanyLifecycleOnSoftDelete` (`plugins/webkul/employees/src/Models/Concerns/`), que reautoriza el `company_id` persistido en `delete()`/`restore()`/`forceDelete()` - lifecycle que `HasStrictCompanyId` no cubre por sí solo. Cada relación tenant-aware de `Employee` (`department_id`, `job_id`, `work_location_id`, `calendar_id`, `parent_id`, `coach_id`) se valida contra el `company_id` ya autorizado vía `ValidatesRelatedCompanyScope::assertRelatedBelongsToCompany()`; `user_id`, `attendance_manager_id` y `leave_manager_id` se validan por membresía (`CompanyScope::allowedCompanyIds()`) ya que `User` no tiene una única `company_id` propia. `Department` corrige además un bug real preexistente: su recursión de jerarquía (`parent_id`/`master_department_id`/`complete_name`) usaba `static::find()`, que con el nuevo scope habría tratado un padre oculto como inexistente en vez de rechazarlo explícitamente - ahora resuelve el árbol vía `withoutGlobalScope(CompanyScope::class)` y compara compañía explícitamente.

`EmployeeSkill` no tiene columna `company_id` propia: usa un scope bespoke nuevo, `EmployeeSkillCompanyScope` (mismo principio que `BankAccountCompanyMembershipScope` de ola 4C, aquí vía `whereHas('employee', ...)` en vez de un pivote), y su escritura se autoriza contra el Employee persistido vía `resolveEffectiveCompanyIdOrFail()`. `Recruitment\JobPosition` valida su propio `manager_id` (relación que el alias agrega, no heredada del owner); `Recruitment\Department` y `JobByPosition` son alias vacíos, heredan el contrato íntegro.

Durante la implementación se encontraron y corrigieron tres bugs preexistentes, no relacionados al aislamiento por compañía en sí, todos dormidos hasta que esta ola los ejercitó por primera vez: `Employee::handlePartnerCreation()`/`handlePartnerUpdation()` pasaban el `parent_id` (un id de Employee) directamente como `Partner.parent_id` (que referencia otros Partners); `EmployeeFactory`/`EmployeeJobPositionFactory` generaban relaciones anidadas con compañías independientes entre sí (corregido con closures que derivan de la compañía ya resuelta); y el seeder `EmployeeSeeder` creaba diez Employees sin `company_id` ni actor/contexto, rompiendo `employees:install` en su totalidad.

### Inventario tras A4D

```
scoped: 127 → 134 (+4 owners: Employee, Department, EmployeeJobPosition, WorkLocation; +3 alias heredados: Recruitment\Department/JobPosition/JobByPosition)
classified_exceptions: 133 → 134 (+1: EmployeeSkill = parent_scoped)
gaps reales: 44 (24+20) → 36 (17+19)
```

95 tests nuevos en `plugins/webkul/employees/tests/Feature/` (8 archivos): `EmployeeCompanyScopeTest.php`, `EmployeeCompanyRelationsTest.php`, `DepartmentCompanyScopeTest.php`, `EmployeeJobPositionCompanyScopeTest.php`, `WorkLocationCompanyScopeTest.php`, `EmployeeSkillCompanyScopeTest.php`, `RecruitmentEmployeeAliasesCompanyScopeTest.php`, `EmployeeFactoryCompanyCoherenceTest.php`. Regresión verificada sin cambio de aserciones: `LeaveCompanyScopeTest.php`/`LeaveAllocationCompanyScopeTest.php` (fixtures ajustadas: creaban Employee/Department sin actor ni contexto, o con una compañía distinta a la del actor ya autenticado - ambos casos ahora fallan cerrado bajo el nuevo contrato), `BankAccountCompanyScopeTest.php` (una fixture ajustada para que la excepción siga siendo causada por el guard de BankAccount, no por la nueva autorización de Employee), auditor 32/32, `composer test` completo.

### Corrección de revisión A4D employees (review 4811425870)

La revisión técnica sobre el head `692c000af815e8b4b7dc0ef7e0b108e760fedbe8` devolvió `CHANGES_REQUIRED_A4D_EMPLOYEES` con cinco hallazgos bloqueantes, todos cerrados en el commit `a185195b2` fast-forward: (1) `GuardsCompanyLifecycleOnSoftDelete` usaba `getOriginal('company_id')`, que retorna `null` de forma indistinguible de "nulo real" bajo una proyección parcial de columnas (`::select('id')->find(...)`), corregido con una reconsulta fresca por PK que bypassea el propio `CompanyScope` y el soft-delete scope; (2) `EmployeeSkill` tenía el mismo problema con `employee_id`, corregido con el mismo patrón (`resolvePersistedEmployeeId()`); (3) `Employee.partner_id` no rechazaba la transición de un Partner existente hacia `null` (disparaba silenciosamente `handlePartnerCreation()`, creando un Partner nuevo), corregido para rechazar cualquier cambio del `partner_id` original, incluida la transición a `null`; (4) `Department` sólo validaba el padre inmediato, `findTopLevelParentId()`/`getCompleteName()` recorrían el resto de la cadena sin validar existencia ni compañía en cada salto, corregido con un helper compartido `resolveValidatedAncestor()` usado por los tres métodos de recorrido; (5) los 95 tests de `employees` no estaban registrados en `phpunit.xml`, corregido con una nueva entrada `EmployeesFeature`.

Se agregaron 4 tests de regresión probando cada hallazgo corregido. Suite `employees` completa: 99/99 tests, 151 assertions. `composer test` ahora ejecuta 1980/1980 tests (4888 assertions, antes 1881/1881), incluyendo `employees` de forma canónica. Inventario sin cambio (correcciones de autorización, no de clasificación): `scoped` 134, `classified_exceptions` 134, gaps reales 36 (17+19), verificado con dos regeneraciones independientes byte a byte idénticas.

### Segunda corrección de revisión A4D employees (review 4811942781, fail-closed owner resolution)

Una segunda revisión técnica sobre el head corregido `4500d8dba9cbc8ea10893f37406bf10abec2e9ee` devolvió `CHANGES_REQUIRED_A4D_EMPLOYEES_FAIL_CLOSED_OWNER_RESOLUTION`: la corrección anterior, al reconsultar el owner por PK, dejaba pasar sin autorización los casos en que el owner no podía resolverse en absoluto, no solo los casos de proyección parcial. `GuardsCompanyLifecycleOnSoftDelete` trataba un `company_id` persistido en `null` (owner inexistente, o corrupción histórica tras un Company FK `onDelete('set null')`) como "unauthorized-but-unblocked" en vez de rechazar la mutación de lifecycle; `EmployeeSkill` retornaba `null` sin autorizar cuando `employee_id` era `null`, dejando pasar create/update/delete/restore/forceDelete sin un parent autorizado.

Cerrado en el commit `f9af800bd` (fast-forward): ambos ahora lanzan `AuthorizationException` cuando el owner no puede resolverse (sin PK, fila inexistente, o `company_id`/`employee_id` persistido en `null`), en vez de omitir el guard. Se agregaron 8 tests de regresión: Employee con `company_id` históricamente `null` (delete/restore/forceDelete rechazados, base intacta) y EmployeeSkill sin `employee_id` resoluble (create/update/delete/restore/forceDelete rechazados). Se preserva el movimiento same-company y el rechazo cross-company ya cubiertos por la primera corrección. Suite `employees` completa: 107/107 tests, 166 assertions. `composer test`: 1988/1988 tests, 4903 assertions. `CompanyScopeAuditorTest`: 32/32. Inventario sin cambio (corrección de autorización, no de clasificación): `scoped` 134, `classified_exceptions` 134, gaps reales 36 (17+19), verificado con dos regeneraciones independientes byte a byte idénticas. Pendiente de re-review sobre el head `f9af800bd1d6fd96cf6d78e8a56907d6ab1c3c77`, no aprobada.

### Corrección de aislamiento del harness de determinismo (review 4814881805)

El re-review de A4D employees (review 4812409030) aprobó el head `e2aa28519993fdd3f9860d9a9912b733a34b4963` y autorizó un dispatch manual de CI (run `30506048590`). Ese run terminó en `failure`: `TestBootstrapHelperDeterminismTest.php` lanzó `ProcessTimedOutException` en su subproceso `run_bootstrap_order.php` (180s, instalando `["accounting","website","projects","manufacturing","employees"]`), y ese test ejecutaba `Schema::dropAllTables()` directamente sobre la base COMPARTIDA usada por el resto de la suite antes de cada escenario. El timeout dejó esa base a medio migrar, y las ~1988 pruebas restantes del job `PHP 8.4 test on ubuntu-latest` fallaron en cascada con `QueryException: table not found` en dominios totalmente ajenos (manufacturing, analytics, maintenance, website), 1699 pasaron, 289 fallaron. Nada relacionado con `employees` ni con la corrección de revisión previa.

Corregido en el commit `test(ci): aislar bootstrap determinista en bases efímeras`: cada escenario de `TestBootstrapHelperDeterminismTest.php` crea ahora su propia base MySQL efímera, única, vía una conexión PDO privilegiada que replica el mismo patrón de `scripts/reset-test-database.php` (usa `TEST_BOOTSTRAP_DB_ROOT_USER`/`TEST_BOOTSTRAP_DB_ROOT_PASSWORD` si están configuradas, o cae de vuelta a `DB_USERNAME`/`DB_PASSWORD` de la app, exactamente el caso de CI, donde ya son `root`/`root`, sin necesitar ningún cambio en el workflow). La base efímera se pasa al subproceso vía `DB_DATABASE` y se incluye explícitamente en `TEST_BOOTSTRAP_ALLOWED_DATABASES`; `run_bootstrap_order.php` valida ahora explícitamente que su conexión real coincide con la base esperada, en vez de asumirlo. El test de doble bootstrap usa su propia tercera base efímera (primera ejecución exitosa, segunda ejecución sobre la misma base rechazada por el guard, huella estructural intacta después). Cada base efímera se elimina en un bloque `finally`, incluso ante timeout o excepción. La huella de la base principal se captura antes y después de cada escenario y debe ser idéntica, nunca se toca. El timeout individual sube de 180 a 300 segundos. Ya no queda ninguna llamada a `Schema::dropAllTables()` sobre la base compartida en este archivo.

Verificado: determinismo 2/2 en dos ejecuciones consecutivas (sin bases efímeras remanentes al finalizar), `CompanyScopeAuditorTest` 32/32, `composer test` 1988/1988 (4904 assertions), auditor regenerado dos veces byte a byte idéntico al inventario committeado (`scoped` 134, `classified_exceptions` 134, gaps reales 36 sin cambio), `git diff --check` limpio. Pendiente de revisión del diff antes de cualquier nuevo dispatch de CI.

---

## A4E: familia recruitments, cierre de gaps residuales

Cierra los diez gaps residuales de `recruitments`: `Webkul\Recruitment\Models\ActivityType`, `Applicant`, `ApplicantApplicantCategory`, `ApplicantInterviewer`, `Candidate`, `CandidateApplicantCategory`, `CandidateSkill`, `JobPositionInterviewer`, `Stage`, `StageJob`.

### Decisiones arquitectónicas

- `Stage` (catálogo kanban) y `ActivityType` (alias de `Webkul\Support\Models\ActivityType`) quedan `global_reference`/`alias`: catálogos compartidos entre compañías por diseño, sin `company_id` propio, sin cambio de código.
- La asociación tenant-aware del kanban vive en `StageJob`, derivada de `JobPosition` (el lado tenant-aware del pivote), no de `Stage`.

### Diseño

- `Applicant`/`Candidate`: `HasCompanyScope` + `HasStrictCompanyId` + `HasFactory` (ninguno de los dos tenía factory funcional antes de esta ola) + un concern local nuevo, `GuardsCompanyLifecycleOnSoftDelete` (mismo patrón que la familia `employees`, ola A4D, duplicado localmente en `recruitments` en vez de importado entre plugins).
- `Applicant`: `candidate_id`/`job_id`/`department_id` validados contra el `company_id` ya autorizado; `recruiter_id` validado por membresía (`User` no tiene una única `company_id`). `stage_id`/`last_stage_id` quedan sin validar a propósito: `Stage` es un catálogo global.
- `Candidate`: `manager_id` validado por membresía; `employee_id`, cuando existe, validado contra el `company_id` ya autorizado. `partner_id` queda administrado por el modelo, igual que el contrato de `Employee` (ola A4D): una vez vinculado, no puede sustituirse por otro Partner ni vaciarse a `null`.
- Seis hijos/pivotes `parent_scoped`: `ApplicantApplicantCategory`/`ApplicantInterviewer` derivan de `Applicant`; `CandidateApplicantCategory`/`CandidateSkill` derivan de `Candidate`; `JobPositionInterviewer`/`StageJob` derivan de `JobPosition` (no de `Stage`). Cinco de los seis son `Pivot` (no `Model` plano), wired vía `->using()` en la relación `belongsToMany` correspondiente (`Applicant::categories()`, `Applicant::interviewer()`, `Candidate::categories()`, `JobPosition::interviewers()`, `Stage::jobs()`), sin ese wiring, `attach()`/`detach()` corren un insert/delete de query builder crudo que evita por completo cualquier guard declarado en la clase pivote. `CandidateSkill` es la excepción: un hijo real (`hasMany`, no pivote), con su propia clave primaria.
- Lectura: un scope genérico nuevo y compartido, `ParentDerivedCompanyScope` (parametrizado por nombre de relación), reemplaza seis clases de scope bespoke casi idénticas, mismo principio que `EmployeeSkillCompanyScope` (ola A4D), generalizado aquí porque los seis casos necesitan exactamente la misma lógica contra una relación distinta.
- `interviewer_id`/`user_id` en los pivotes con FK a `User` (`ApplicantInterviewer`, `JobPositionInterviewer`, `CandidateSkill`) validados por membresía además de por compañía del padre.

### Bugs preexistentes encontrados y corregidos

- `Applicant::createEmployee()`/`Candidate::createEmployee()`: la clave `'company_id'` aparecía duplicada en el array de creación del Employee, un array literal de PHP solo conserva el último valor duplicado, descartando el primero sin ningún indicio de que ambos pudieran divergir. Corregido a una única fuente de verdad; en `Applicant::createEmployee()` se agrega además un rechazo explícito si `Applicant.company_id` y `Candidate.company_id` alguna vez divergen.
- `Candidate::handlePartnerCreation()`/`handlePartnerUpdation()`: el fallback de `creator_id` era `Auth::user()->id ?? $candidate->id`, leer una propiedad de `null` degrada a warning, no a error fatal, así que sin actor autenticado (el caso exacto de un fixture creado dentro de `CompanyContext::runForAllCompanies()`) esto caía silenciosamente hasta `$candidate->id`, la propia clave primaria del Candidate, no un id de usuario, violando la FK `creator_id` de `partners_partners` en cuanto ese id no coincidía por casualidad con un usuario real. Corregido para caer a `$candidate->creator_id` (ya resuelto, también nullable).

### Bugs preexistentes dormidos, encontrados y evitados sin tocar el archivo dueño

Ninguno de estos está en los diez modelos autorizados; se evitaron con factories directas (`::create()`) o overrides explícitos en los tests nuevos, sin modificar el archivo dueño:

- `Degree`, `ApplicantCategory` y `Stage` no tenían `HasFactory`/`newFactory()` en absoluto, el resolver de nombre de factory por defecto de Laravel solo maneja modelos bajo el namespace raíz de la app, nunca `Webkul\...`, así que `Modelo::factory()` fallaba con una clase inexistente para cualquier modelo de un plugin sin `newFactory()` explícito.
- `StageFactory::definition()` deja `legend_blocked`/`legend_done`/`legend_normal` en `null`, pero esas tres columnas son `NOT NULL` sin default, se usa `->withLegend()` explícitamente en cada `StageFactory::new()->create()`.
- `SkillTypeFactory::definition()` fija una columna `status` que nunca existió en `employees_skill_types` (la columna real es `is_active`), evitado creando `SkillType` directamente con solo columnas reales.
- `SkillLevelFactory`/`SkillFactory` anidan su propio default roto de `skill_type_id`/`SkillTypeFactory::factory()` (ya conocido de la ola A4D), evitado igual, con `::create()` directo.

### Resultados verificados

- `scoped`: 134 -> 136 (+2: `Applicant`, `Candidate`).
- `classified_exceptions`: 134 -> 142 (+8: `ActivityType`, `Stage`, `ApplicantApplicantCategory`, `ApplicantInterviewer`, `CandidateApplicantCategory`, `CandidateSkill`, `JobPositionInterviewer`, `StageJob`).
- gaps reales: 36 (17+19) -> 26 (15+11).
- 81 tests nuevos en `plugins/webkul/recruitments/tests/Feature/CompanyScope/` (5 archivos): `ApplicantCompanyScopeTest`, `ApplicantPivotsCompanyScopeTest`, `CandidateCompanyScopeTest`, `CandidatePivotsCompanyScopeTest`, `JobPositionPivotsCompanyScopeTest`.
- `phpunit.xml`: nueva suite `RecruitmentCompanyScopeFeature` (solo el subdirectorio nuevo, no el resto de la suite histórica de `recruitments`, que tiene fallos preexistentes no relacionados).
- `composer test`: 2069/2069 tests, 5032 assertions (antes 1988/1988).
- Auditor: regenerado dos veces, salida byte a byte idéntica; `--fail-on-missing`: exit 1 únicamente por los 26 gaps reales conocidos.
- `git diff --check`: limpio. Pint: limpio en los ~31 archivos tocados/nuevos.
- Pendiente de revisión técnica del diff publicado.

---

## A4F: anticipos de venta (familia sales)

Cierra 2 de los 26 gaps residuales: `Webkul\Sale\Models\AdvancedPaymentInvoice` y `Webkul\Sale\Models\AdvancedPaymentInvoiceOrderSale`.

### Composición y contrato

- `AdvancedPaymentInvoice`: owner estricto, `HasCompanyScope` + `HasStrictCompanyId`. Compañía propia, obligatoria, autorizada e inmutable.
- `AdvancedPaymentInvoiceOrderSale`: pivote `parent_scoped`, derivado del anticipo, con clase `Pivot` propia y `->using()` en la relación `belongsToMany` (sin ese wiring `attach()`/`detach()` corren SQL crudo y evitan todo guard declarado).
- `creator_id`: contrato consolidado en esta ola, luego reutilizado por A4G y A4H.

### Correcciones de revisión aplicadas

- Review 4827999112, `CHANGES_REQUIRED_A4F_CREATOR_WRITE_PATH`: `creator_id` era mutable después de crear y un creator inexistente se aceptaba en silencio. Corregido en `0c68eeee3` (inmutable tras crear, creator inexistente rechazado explícitamente).
- Re-review 4830829763, `CHANGES_REQUIRED_A4F_CREATOR_NULL_TRANSITION`: la inmutabilidad no cubría filas con `creator_id` nulo por `nullOnDelete()`, que podían transitar hacia un usuario arbitrario. Corregido en `86612bace` (rechazo por `isDirty`, sin condicionar al valor original).

### Estado final

Implementada en `cb2647a5f`, corregida en `0c68eeee3` y `86612bace`, **aprobada** mediante re-review 4832889816 (`A4F_ADVANCE_PAYMENTS_APPROVED_LOCAL_GATE`). Sin dispatch de CI remoto: validación 100% local.

### Inventario tras A4F

`136/142/26` → `137 scoped / 143 classified exceptions / 24 gaps reales` (14 con `company_id`, 10 sin). Sin cambio tras ninguna de las dos correcciones de `creator_id`. `composer test`: 2108/2108, 5120 assertions. Suite `sales`: 98/98, 257 assertions. Tests focalizados: 27/27, 52 assertions.

---

## A4G: equipos de venta (familia sales)

Cierra 2 de los 24 gaps residuales: `Webkul\Sale\Models\Team` y `Webkul\Sale\Models\TeamMember`, más el contrato `Order.team_id` exigido por la revisión 4830829763.

### Composición y contrato

- `Team`: owner estricto, `HasCompanyScope` + `HasStrictCompanyId`.
- `TeamMember`: pivote `parent_scoped` derivado de `Team`, con clase `Pivot` propia y `->using()`.
- `Order.team_id`: validado contra la compañía de la orden en el mismo listener de `saving`, registrado primero, antes de que se propague estado a las líneas. Incluido en esta unidad por la misma razón que `Order.sale_order_template_id` se incluyó en A4H.

### Correcciones de revisión aplicadas

Review 4834206687, `CHANGES_REQUIRED_A4G_ORDER_AND_BULK_GUARDS`: un `Team` soft-deleted de la propia compañía seguía siendo asignable, `toggle()` no tenía guard, el guard de `Order` corría después de propagar estado a las líneas, y el formulario filtraba por la unión de compañías del actor en vez de por la compañía efectiva. Corregido en `aef0a7313`.

### Estado final

Implementada en `7df4f4e60`, corregida en `aef0a7313`, **aprobada** mediante re-review 4834882601 (`A4G_SALES_TEAMS_APPROVED_LOCAL_GATE`). Sin dispatch de CI remoto.

### Inventario tras A4G

`137/143/24` → `138 scoped / 144 classified exceptions / 22 gaps reales` (13 con `company_id`, 9 sin). Sin cambio tras la corrección de rutas residuales, verificado con dos corridas byte-identical. `composer test`: 2141/2141, 5199 assertions. Suite `sales`: 131/131, 336 assertions. Tests focalizados: 33/33, 79 assertions.

---

## A4H: plantillas de pedido (familia sales)

Cierra 2 de los 22 gaps residuales: `Webkul\Sale\Models\OrderTemplate` y `Webkul\Sale\Models\OrderTemplateProduct`, más el contrato `Order.sale_order_template_id`.

### Composición y contrato

- `OrderTemplate`: owner estricto, `HasCompanyScope` + `HasStrictCompanyId`, compañía obligatoria, autorizada e inmutable, y guard de `deleting` que reautoriza la compañía persistida. No lleva `GuardsCompanyLifecycleOnSoftDelete` porque el modelo no usa `SoftDeletes`: no hay `restore` ni `forceDelete` que guardar, y los hijos caen con el padre por el `cascadeOnDelete` de la FK.
- `journal_id`: validado contra la compañía del template en cada save. La columna es un `integer` simple, sin foreign key declarada en la migración, así que un id obsoleto o ajeno es plenamente posible; ambos se rechazan. Esta validación de aplicación es el único guard que la relación tiene.
- `OrderTemplateProduct`: `parent_scoped` con la forma ya aceptada para `OrderLine`. La columna `company_id` existe, pero la autoridad es el template persistido. El padre bajo el que la fila ya está se reautoriza en todo update y delete antes de considerar cualquier otra cosa; solo después se resuelve y autoriza el destino. Lectura vía `ParentDerivedCompanyScope('orderTemplate')`.
- Desaparecen los tres defaults globales del `creating()` anterior (`Company::first()`, `Product::first()`, `UOM::first()`): la compañía viene del template, `product_id` es obligatorio en líneas reales y se valida en ambos sentidos, y la UOM se deriva del producto que la línea describe.

### Correcciones de revisión aplicadas

- Review 4835591772, `CHANGES_REQUIRED_A4H_PRODUCT_AND_LAYOUT_INTEGRITY`: `assertCoherentWithTemplate()` aceptaba un Product soft-deleted de la misma compañía y un `product_id` inexistente (ambos vía el helper compartido genérico), y las filas SECTION/NOTE admitían `product_id`/`product_uom_id` de otra compañía o su alta posterior vía update sin revalidación. Corregido en `bea9aaccf`: resolución local del Product con `withTrashed()` propia que rechaza inexistente y trashed antes de comparar compañía, SECTION/NOTE exigen ambos campos nulos de forma explícita, y `product_uom_id` se incorpora a la revalidación de updates.
- Re-review 4836835423, `CHANGES_REQUIRED_A4H_UNKNOWN_DISPLAY_TYPE`: `describesAProduct()` seguía definido por negación (todo lo que no fuera `section` ni `note`), de modo que un `display_type` inventado con un Product existente, activo y de la propia compañía del template pasaba todos los guards restantes y se persistía como línea real, con semántica de layout que ningún lector del repositorio interpreta (`OrderTemplate::lines()`, `sections()` y `notes()` filtran por `NULL`, `section` y `note` y la saltan en silencio). Corregido en `7ccdc540e`: `display_type` se valida de forma positiva contra el conjunto cerrado `NULL`/`section`/`note` y cualquier otro valor lanza `AuthorizationException` antes de clasificar la fila y antes de resolver `company_id`.

### Bugs latentes corregidos al ejercer las factories

`OrderTemplate` necesitaba `newFactory()` explícito, y `OrderTemplateFactory` declaraba cinco columnas inexistentes en toda migración (`recurrence`, `recurrence_period`, `mail_template_id`, `auto_confirmation`, `confirmation_mail_template`), de modo que toda inserción vía factory fallaba con `Unknown column 'recurrence'`. Misma clase que el `advance_payment_method` de A4F.

### Fuera de alcance por decisión explícita

Las cuatro páginas Filament de `QuotationTemplateResource`, que apuntan a una clase Resource inexistente y por tanto no son alcanzables, y las dos policies cuyos permisos `*_sale_quotation::template` no existen en ningún otro punto del repositorio. Quedan registradas como deuda, sin restaurar ni eliminar.

### Estado final

Implementada en `2a5563ce1`, corregida en `bea9aaccf` y `7ccdc540e`, **aprobada** mediante review 4837339950 (`A4H_ORDER_TEMPLATES_APPROVED_LOCAL_GATE`) sobre `7ccdc540e200a8cc6363c15ccf13d2fc24cc9b45`. Sin dispatch de CI remoto.

### Inventario tras A4H

`138/144/22` → `139 scoped / 145 classified exceptions / 20 gaps reales` (11 con `company_id`, 9 sin). `composer test`: 2175/2175, 5287 assertions. Suite `sales`: 165/165, 424 assertions. Tests focalizados: 34/34, 88 assertions.

---

## Inventario consolidado tras A4F, A4G y A4H

Delta acumulado desde el checkpoint de A4E: `136/142/26` → `139/145/20`.

`docs/security/company-scope-pr4-inventory.json` regenerado con el auditor real sobre una instalación fresh aislada (`erp:install --force -n` + los 20 comandos `<plugin>:install -n`, misma secuencia que el job de CI), tres corridas byte a byte idénticas entre sí y con el archivo committeado:

```json
{
  "total": 304,
  "scoped": 139,
  "classified_exceptions": 145,
  "real_gaps_with_company_id": 11,
  "real_gaps_without_company_id": 9,
  "table_missing": 0,
  "inspection_errors": 0,
  "manifest_violations": 0
}
```

Las seis filas que cambian respecto del snapshot anterior son exactamente las seis olas cerradas, sin ninguna transición colateral:

| Modelo | Antes | Después |
|---|---|---|
| `Webkul\Sale\Models\AdvancedPaymentInvoice` | `real_gap_company_column` | `scoped` |
| `Webkul\Sale\Models\AdvancedPaymentInvoiceOrderSale` | `real_gap_without_company_column` | `classified_exception` / `parent_scoped` |
| `Webkul\Sale\Models\Team` | `real_gap_company_column` | `scoped` |
| `Webkul\Sale\Models\TeamMember` | `real_gap_without_company_column` | `classified_exception` / `parent_scoped` |
| `Webkul\Sale\Models\OrderTemplate` | `real_gap_company_column` | `scoped` |
| `Webkul\Sale\Models\OrderTemplateProduct` | `real_gap_company_column` | `classified_exception` / `parent_scoped` |

Composición del manifest en este punto (145 entradas, 0 violaciones): 50 `alias`, 40 `global_reference`, 39 `parent_scoped`, 12 `not_tenancy`, 2 `multi_company_membership`, 1 `global_party_identity`, 1 `root_company_entity`.

Los 20 gaps reales restantes, por clúster:

- Con `company_id` (11): `chatter` Attachment/Message; `employees` Calendar/CalendarLeave; `payments` PaymentToken/PaymentTransaction; `security` Invitation; `support` Calendar/CalendarLeave/UtmCampaign; `time-off` CalendarLeave.
- Sin columna propia (9): `chatter` Follower; `employees` CalendarAttendance/EmployeeEmployeeCategory/EmployeeResume/JobPositionSkill; `sales` ActivityType/OrderOption/Tag; `support` CalendarAttendance.

El clúster `sales` queda reducido a tres filas (`ActivityType`, `OrderOption`, `Tag`), todas sin `company_id` propio.

Ninguna de las tres olas tuvo dispatch de CI remoto: A4F, A4G y A4H se validaron 100% localmente. El único CI verde del PR sigue siendo el de A4E sobre `6faa837f5` (run `30594012178`).

---

## Estado

```
PR 4 (PR #18, feat/company-scope-remaining-plugins): checkpoint aprobado + ola 4A implementada (3 rondas)
  - checkpoint (auditor/manifest/CI): sin cambios respecto a la 2a/3a/4a revisión, ver arriba
  - ola 4A ronda 1: TableView/TableViewFavorite (IDOR cerrado, resolver server-side), Project
    (HasCompanyScope + HasStrictCompanyId), Task/TaskStage (derivación desde Project,
    ValidatesRelatedCompanyScope), Milestone (parent-scoped vía whereHas + policy),
    Timesheet ambas clases (grafo Task→Project→company validado)
  - ola 4A ronda 2 (revisión 4739111455): TableView/TableViewFavorite ya no aceptan userId
    externo (siempre Auth::id(), inmutable); Milestone reautoriza el Project ORIGINAL antes
    de aceptar un retargeting, policy reconsulta por PK; Analytic\Record (owner físico de
    analytic_records) recibe HasCompanyScope propio, resolución polimórfica Task↔Project
    cross-validada; Task.stage_id/parent_id validados contra el mismo project_id/compañía;
    create/detach sin Project(Task)/Task(Timesheet) rechazado salvo fila ya huérfana;
    TestBootstrapHelper determinista (20 plugins, incluido website, fix de transacción vs DDL);
    fix acotado en manufacturing/Warehouse.php (Location::withoutGlobalScope, autorizado
    explícitamente al descubrirse durante el punto anterior)
  - ola 4A ronda 3 (A18-01/A18-02/A18-03): root cause del no-determinismo entre procesos
    encontrado y documentado (isPluginInstalled() se resuelve en boot, antes de que corra
    TestBootstrapHelper); guard assertDatabaseNotAlreadyBootstrapped() falla alto en vez de
    corromper el esquema; regresión con procesos reales (TestBootstrapHelperDeterminismTest.php)
    prueba mismo esquema final sin importar el orden Y falla loud en reuso sin recrear; Pint
    aplicado sobre los 27 archivos del delta completo, verificado sin cambio de comportamiento
  - 123 excepciones formalizadas (1 global_party_identity, 45 alias, 38 global_reference,
    25 parent_scoped, 12 not_tenancy, 1 root_company_entity, 1 multi_company_membership)
  - docs/security/company-scope-pr4-inventory.json regenerado (304 filas): scoped 106→112,
    classified_exceptions 120→123, gaps reales 78→69 (sin cambio desde ronda 2)
  - 81 tests de company-scope + 2 tests nuevos de determinismo del harness + suite completa
    del monorepo (vendor/bin/pest --exclude-group=gold-standard-dataset) 1641/1641 verde desde
    una base limpia, sin depender del orden
  - CI: en freeze de presupuesto (workflow_dispatch only, PR #19 mergeado a main):
    validación 100% local por instrucción explícita
Ola 4B (publicada hasta 499a84955e4733401087cd2a1d45dd4e9e3725c5): Maintenance, ProjectStage,
  ActivityPlan, Time Off/Leave, Invitations, BankAccount
  - Maintenance: Team/EquipmentCategory/Equipment/MaintenanceRequest (HasCompanyScope +
    HasStrictCompanyId, relaciones validadas contra su propia compañía), Stage sin cambio
  - ProjectStage: HasCompanyScope + IncludesSharedCompanyRows (patrón Route/Location) +
    reautorización en cada update, gap encontrado en Route/Location mismos (no reautorizan
    updates de filas no compartidas), señalado para una ola futura
  - ActivityPlan: HasCompanyScope + IncludesSharedCompanyRows en el owner físico (Support),
    4 alias heredan automáticamente; ActivityPlanTemplate parent-scoped vía plan_id;
    ActivityType clasificado global_reference (catálogo cross-plugin por columna plugin)
  - Time Off/Leave: Leave (deriva de Employee), LeaveType/LeaveAccrualPlan/LeaveMandatoryDay
    (HasCompanyScope + HasStrictCompanyId propios), LeaveAllocation (employee_company_id,
    scope propio sin HasCompanyScope), LeaveAccrualLevel/UserLeaveType (parent-scoped);
    CalendarLeave excluido de esta ola (owner real es Calendar/scheduling, no Leave)
  - Invitaciones: migración nueva (company_id/role_id/token/invited_by/expires_at/
    accepted_at), emisión captura compañía/rol del actor, aceptación con transacción +
    lockForUpdate + validación de estado, sin HasCompanyScope por diseño (ruta de invitado)
  - BankAccount: tabla de membresía partners_bank_account_companies, creación desde una
    compañía la habilita, Journal la habilita al designarla, Payment/Move/PaymentRegister/
    Employee la validan sin habilitarla, backfill determinista desde Employee y
    PaymentRegister, sin fallback implícito
  - Invitation y BankAccount quedan clasificados como gap real en el inventario por diseño:
    ninguno usa HasCompanyScope, la protección real vive en mecanismos que el auditor
    automático todavía no reconoce
  - docs/security/company-scope-pr4-inventory.json regenerado (304 filas): scoped 112→126,
    classified_exceptions 123→129, gaps reales 69 (38+31)→49 (25+24)
  - 135 tests de company-scope nuevos entre las 6 familias
  - ~15 bugs preexistentes en factories/enums encontrados y corregidos porque bloqueaban
    las fixtures de esta ola, ninguno relacionado a company-scope (detalle por familia arriba)
Ola 4C (publicada en 3fed913b090d824960d9ed84040bd0b9e570a06c): BankAccount, aislamiento de lectura
  - Webkul\Partner\Models\BankAccount (owner físico): BankAccountCompanyMembershipScope nuevo,
    misma precedencia que CompanyScope::apply() filtrando por enabledCompanies() en vez de
    company_id; forAllCompanies() explícito y auditado, restringido a super_admin
  - Contact\BankAccount, Accounting\BankAccount, Invoice\BankAccount: heredan el scope por late
    static binding, verificado empíricamente, sin cambios propios
  - ensureEnabledForCompany()/assertEnabledForCompany()/assertBelongsToPartner() excluyen
    explícitamente solo el nuevo scope, preservando su capacidad de operar sobre filas ocultas
    para el actor
  - manifest: Partner\BankAccount clasificado multi_company_membership, 3 alias clasificados
    alias; ExceptionManifest.php (core) no se tocó
  - Invitation queda fuera de esta ola: sin superficie de enumeración real, cerrar su
    clasificación requeriría una categoría de taxonomía nueva en código core compartido,
    diferido a su propia autorización explícita
  - docs/security/company-scope-pr4-inventory.json regenerado (304 filas): scoped 126 (sin
    cambio), classified_exceptions 129→133, gaps reales 49 (25+24)→45 (25+20)
  - 21 tests añadidos por la ola 4C (17 en BankAccountCompanyScopeTest.php, 4 en
    BankAccountReadIntegrationTest.php); 32/32 focalizados, 47 assertions
A4D-0 (hotfix, publicado): CurrencyRate, aislamiento company-or-shared
  - Support\CurrencyRate: HasCompanyScope + IncludesSharedCompanyRows, sin migración nueva
  - controller/request sin cambios: autoridad completa en el modelo (scope + boot())
  - company_id inmutable tras crear (A->B, A->null, null->A rechazados, incluso para
    super_admin); filas compartidas (company_id null) mutables solo por super_admin o
    proceso sin usuario en ALL_COMPANIES/BOOTSTRAP explícito
  - sin restore()/force-delete(): currency_rates no tiene deleted_at, fuera de alcance
  - docs/security/company-scope-pr4-inventory.json regenerado (304 filas): scoped 126->127,
    classified_exceptions 133 (sin cambio), gaps reales 45 (25+20)->44 (24+20)
  - 33 tests nuevos (21 CurrencyRateCompanyScopeTest.php, 12 CurrencyRateApiCompanyScopeTest.php);
    CurrencyRateTest.php preexistente (11 tests) con fixtures ajustadas, sin cambio de asserts
  - aprobado mediante revisión técnica (review 4808109049)
A4D (familia employees, publicada): Employee, Department, EmployeeJobPosition, WorkLocation,
  EmployeeSkill, alias de Recruitment
  - Employee/Department/EmployeeJobPosition/WorkLocation: HasCompanyScope + HasStrictCompanyId;
    delete/restore/forceDelete autorizados vía concern nuevo GuardsCompanyLifecycleOnSoftDelete
  - Employee: department_id/job_id/work_location_id/calendar_id/parent_id/coach_id validados
    contra el company_id ya autorizado; user_id/attendance_manager_id/leave_manager_id
    validados por membresía (User no tiene una única company_id propia)
  - Department: corregido bug preexistente de static::find() en la jerarquía (parent oculto
    ya no se trata como inexistente); manager_id validado contra Employee
  - EmployeeSkill: sin company_id propio, scope bespoke EmployeeSkillCompanyScope (via
    whereHas('employee'), mismo principio que BankAccountCompanyMembershipScope de ola 4C),
    escritura autorizada vía resolveEffectiveCompanyIdOrFail() contra el Employee persistido
  - Recruitment\Department/JobPosition/JobByPosition: heredan el contrato por late static
    binding, verificado directamente; JobPosition valida su propio manager_id (no heredado)
  - tres bugs preexistentes encontrados y corregidos (no relacionados al aislamiento en sí):
    Employee.parent_id pasado como Partner.parent_id, EmployeeFactory/EmployeeJobPositionFactory
    con relaciones anidadas de compañías independientes, EmployeeSeeder sin company_id/contexto
  - manifest: EmployeeSkill clasificado parent_scoped; alias de Recruitment no se agregan al
    manifest (se detectan directamente como scoped)
  - docs/security/company-scope-pr4-inventory.json regenerado (304 filas): scoped 127->134,
    classified_exceptions 133->134, gaps reales 44 (24+20)->36 (17+19)
  - 95 tests nuevos en plugins/webkul/employees/tests/Feature/ (8 archivos); regresión de
    LeaveCompanyScopeTest.php/LeaveAllocationCompanyScopeTest.php/BankAccountCompanyScopeTest.php
    con fixtures ajustadas, sin cambio de aserciones de negocio
  - revisión técnica (review 4811425870) sobre 692c000af815e8b4b7dc0ef7e0b108e760fedbe8:
    CHANGES_REQUIRED_A4D_EMPLOYEES, 5 hallazgos bloqueantes
Corrección de revisión A4D employees (publicada en a185195b2246a8b5ece3f49dde0179d8df27ac03):
  - GuardsCompanyLifecycleOnSoftDelete: getOriginal('company_id') reemplazado por reconsulta
    fresca por PK (bypass CompanyScope + soft-delete scope) - null bajo proyección parcial
  - EmployeeSkill: mismo problema con employee_id, mismo patrón (resolvePersistedEmployeeId())
  - Employee.partner_id: ahora rechaza también la transición existente->null, no solo
    existente->distinto (evitaba que handlePartnerCreation() creara un Partner nuevo)
  - Department: findTopLevelParentId()/getCompleteName() ahora validan cada salto de la
    cadena de ancestros (resolveValidatedAncestor()), no solo el padre inmediato
  - phpunit.xml: nueva suite EmployeesFeature; composer test pasa de 1881/1881 a 1980/1980
    tests (4888 assertions), employees incorporado de forma canónica
  - 4 tests de regresión nuevos, uno por hallazgo; suite employees completa 99/99, 151 asserts
  - inventario sin cambio (correcciones de autorización, no de clasificación): scoped 134,
    classified_exceptions 134, gaps reales 36 (17+19)
  - revisión técnica (review 4811942781) sobre 4500d8dba9cbc8ea10893f37406bf10abec2e9ee:
    CHANGES_REQUIRED_A4D_EMPLOYEES_FAIL_CLOSED_OWNER_RESOLUTION, 1 hallazgo bloqueante
Segunda corrección de revisión A4D employees (publicada en f9af800bd1d6fd96cf6d78e8a56907d6ab1c3c77):
  - GuardsCompanyLifecycleOnSoftDelete: un company_id resuelto en null (owner inexistente o
    corrupción histórica) ya no queda unauthorized-but-unblocked, ahora lanza
    AuthorizationException; solo con company_id válido se llama assertCanWriteCompany()
  - EmployeeSkill: authorizeAgainstPersistedEmployee() ya no intercepta un employee_id null
    retornando null, delega siempre en resolveEffectiveCompanyIdOrFail() (que ya falla
    cerrado); afecta create/update/delete/restore/forceDelete por igual
  - 8 tests de regresión nuevos: Employee con company_id históricamente null (delete/restore/
    forceDelete rechazados), EmployeeSkill sin employee_id resoluble (create/update/delete/
    restore/forceDelete rechazados); suite employees completa 107/107, 166 asserts
  - composer test: 1988/1988 tests, 4903 assertions; CompanyScopeAuditorTest: 32/32
  - inventario sin cambio (corrección de autorización, no de clasificación): scoped 134,
    classified_exceptions 134, gaps reales 36 (17+19)
  - re-review (4812409030) aprobado sobre e2aa28519993fdd3f9860d9a9912b733a34b4963,
    A4D_EMPLOYEES_APPROVED_FOR_CI
Dispatch de CI (run 30506048590, workflow_dispatch, head e2aa28519993fdd3f9860d9a9912b733a34b4963):
  - conclusion: failure. Frontend Assets Build y Company Scope Global Audit: success
  - PHP 8.4 test on ubuntu-latest: failure. TestBootstrapHelperDeterminismTest.php lanzo
    ProcessTimedOutException (180s) en run_bootstrap_order.php, Schema::dropAllTables()
    sobre la base COMPARTIDA dejo esa base a medio migrar, cascada de 289 QueryException
    no relacionadas a employees (manufacturing/analytics/maintenance/website); 1699 passed
  - no relacionado con employees ni con ninguna de las dos correcciones previas
Correccion de aislamiento del harness de determinismo (review 4814881805):
  - TestBootstrapHelperDeterminismTest.php: cada escenario crea su propia base MySQL
    efimera via PDO privilegiado (mismo patron que scripts/reset-test-database.php,
    cae de vuelta a DB_USERNAME/DB_PASSWORD cuando no hay TEST_BOOTSTRAP_DB_ROOT_* -
    exactamente el caso de CI, root/root, sin cambio de workflow)
  - run_bootstrap_order.php valida explicitamente que la base conectada coincida con
    la esperada, ya no la asume
  - test de doble bootstrap usa su propia tercera base efimera; timeout individual
    180 -> 300 segundos; cada base efimera se elimina en finally
  - huella de la base principal capturada antes/despues de cada escenario, identica
  - determinismo 2/2 (sin bases efimeras remanentes), CompanyScopeAuditorTest 32/32,
    composer test 1988/1988 (4904 assertions), auditor sin cambio (134/134/36)
  - pendiente de revision del diff antes de cualquier nuevo dispatch de CI
Ola A4E (familia recruitments, publicada): ActivityType, Applicant, ApplicantApplicantCategory,
  ApplicantInterviewer, Candidate, CandidateApplicantCategory, CandidateSkill,
  JobPositionInterviewer, Stage, StageJob
  - Stage/ActivityType: global_reference/alias, catalogos compartidos sin cambio de codigo;
    StageJob deriva de JobPosition, no de Stage
  - Applicant/Candidate: HasCompanyScope + HasStrictCompanyId + HasFactory (ninguno tenia
    factory funcional antes); concern local GuardsCompanyLifecycleOnSoftDelete (duplicado de
    employees, no compartido entre plugins)
  - Applicant: candidate_id/job_id/department_id validados contra el company_id ya
    autorizado; recruiter_id por membresia; stage_id/last_stage_id sin validar (catalogo
    global)
  - Candidate: manager_id por membresia; employee_id validado contra el company_id ya
    autorizado; partner_id administrado por el modelo (mismo contrato que Employee, A4D)
  - seis hijos/pivotes parent_scoped: ApplicantApplicantCategory/ApplicantInterviewer
    derivan de Applicant; CandidateApplicantCategory/CandidateSkill derivan de Candidate;
    JobPositionInterviewer/StageJob derivan de JobPosition; cinco son Pivot wired via
    ->using() (attach/detach reales respetan los guards, no un insert/delete crudo);
    CandidateSkill es hijo real (hasMany), no pivote
  - scope generico nuevo ParentDerivedCompanyScope (parametrizado por relacion) en vez de
    seis scopes bespoke casi identicos
  - dos bugs preexistentes corregidos: company_id duplicado en createEmployee() de
    Applicant/Candidate (un array literal solo conserva la ultima clave duplicada);
    Candidate.handlePartnerCreation()/handlePartnerUpdation() usaba $candidate->id (la
    propia PK) como fallback de creator_id sin actor autenticado
  - bugs dormidos evitados sin tocar el archivo dueno (fuera de los diez modelos
    autorizados): Degree/ApplicantCategory/Stage sin HasFactory; StageFactory con
    legend_blocked/legend_done/legend_normal en null contra columnas NOT NULL;
    SkillTypeFactory con columna status inexistente; SkillLevelFactory/SkillFactory con el
    mismo default roto de A4D
  - docs/security/company-scope-pr4-inventory.json regenerado (304 filas): scoped 134->136,
    classified_exceptions 134->142, gaps reales 36 (17+19)->26 (15+11)
  - 81 tests nuevos en plugins/webkul/recruitments/tests/Feature/CompanyScope/ (5 archivos);
    phpunit.xml incorpora RecruitmentCompanyScopeFeature (solo el subdirectorio nuevo)
  - composer test: 2069/2069 tests, 5032 assertions (antes 1988/1988)
  - pendiente de revision tecnica del diff publicado
A4F (anticipos de venta, familia sales): implementada en cb2647a5f, APROBADA
  - AdvancedPaymentInvoice owner estricto (HasCompanyScope + HasStrictCompanyId);
    AdvancedPaymentInvoiceOrderSale pivote parent_scoped con clase Pivot y ->using()
  - review 4827999112 (CHANGES_REQUIRED_A4F_CREATOR_WRITE_PATH) corregida en 0c68eeee3;
    re-review 4830829763 (CHANGES_REQUIRED_A4F_CREATOR_NULL_TRANSITION) corregida en
    86612bace; aprobada por re-review 4832889816 (A4F_ADVANCE_PAYMENTS_APPROVED_LOCAL_GATE)
  - inventario 136/142/26 -> 137/143/24; composer test 2108/2108, 5120 assertions
A4G (equipos de venta, familia sales): implementada en 7df4f4e60, APROBADA
  - Team owner estricto; TeamMember pivote parent_scoped; contrato Order.team_id validado
    en el listener de saving registrado primero, antes de propagar estado a las lineas
  - review 4834206687 (CHANGES_REQUIRED_A4G_ORDER_AND_BULK_GUARDS) corregida en aef0a7313;
    aprobada por re-review 4834882601 (A4G_SALES_TEAMS_APPROVED_LOCAL_GATE)
  - inventario 137/143/24 -> 138/144/22; composer test 2141/2141, 5199 assertions
A4H (plantillas de pedido, familia sales): implementada en 2a5563ce1, APROBADA
  - OrderTemplate owner estricto con journal_id validado contra su propia compania (columna
    integer sin FK declarada, asi que un id obsoleto o ajeno es posible y se rechaza);
    OrderTemplateProduct parent_scoped (forma de OrderLine); contrato
    Order.sale_order_template_id; eliminados los defaults globales Company::first()/
    Product::first()/UOM::first() del creating() anterior
  - review 4835591772 (CHANGES_REQUIRED_A4H_PRODUCT_AND_LAYOUT_INTEGRITY) corregida en
    bea9aaccf; re-review 4836835423 (CHANGES_REQUIRED_A4H_UNKNOWN_DISPLAY_TYPE) corregida
    en 7ccdc540e (display_type validado de forma positiva contra NULL/section/note, fail
    closed antes de clasificar la fila y antes de resolver company_id); aprobada por review
    4837339950 (A4H_ORDER_TEMPLATES_APPROVED_LOCAL_GATE) sobre 7ccdc540e
  - inventario 138/144/22 -> 139/145/20; composer test 2175/2175, 5287 assertions
Inventario consolidado tras A4F-A4H: 136/142/26 -> 139/145/20 (11 con company_id, 9 sin)
  - docs/security/company-scope-pr4-inventory.json regenerado con el auditor real sobre una
    instalacion fresh aislada, tres corridas byte a byte identicas; delta de exactamente
    seis filas, todas del plugin sales
  - manifest: 145 entradas, 0 violaciones (50 alias, 40 global_reference, 39 parent_scoped,
    12 not_tenancy, 2 multi_company_membership, 1 global_party_identity, 1 root_company_entity)
  - sin dispatch de CI remoto para A4F, A4G ni A4H: validacion 100% local; el unico CI verde
    del PR sigue siendo el de A4E sobre 6faa837f5 (run 30594012178)
A4I (familia calendarios: Calendar, CalendarAttendance, CalendarLeave, plugins support/
  employees/time-off): implementada, APROBADA
  - Calendar (owner fisico en support): company_or_shared (HasCompanyScope +
    IncludesSharedCompanyRows), sustentado por el CalendarSeeder real (fila company_id
    NULL, "Standard 40 hours/week", referenciada por WorkCenterResource en toda
    instalacion fresh). guardSharedRowMutation() sigue el precedente estricto de
    CurrencyRate, no el de ActivityPlan: un proceso sin usuario autenticado tambien
    necesita un CompanyContext ALL_COMPANIES/BOOTSTRAP explicito para mutar una fila
    compartida, la sola ausencia de usuario ya no basta. company_id inmutable tras crear;
    update/delete/restore/forceDelete reautorizan la fila persistida; forceDelete
    rechazado mientras existan CalendarLeave referenciandolo (el FK real es SET NULL, y
    dejar pasar esa nulificacion reabriria el hueco de CalendarLeave.calendar_id nulo que
    esta misma ola cierra). resource_type/resource_id retirados de $fillable: esas
    columnas nunca existieron en la tabla calendars.
  - CalendarAttendance: parent_scoped, sin company_id propio. Nuevo scope local
    ParentDerivedCompanyOrSharedScope('calendar') (no se toco ParentDerivedCompanyScope
    ni sus dos copias en sales/recruitments): variante consciente de company_or_shared
    para un padre que puede ser Calendar compartido, mismo orWhereNull('company_id') que
    ya usa CompanyScope::applyCompanyFilter(). Escritura contra un padre de compania
    reautoriza esa compania; contra un padre compartido exige super_admin o contexto de
    sistema explicito (mismo precedente estricto de Calendar). Retargeting de calendar_id
    prohibido. resource_type/resource_id deben permanecer NULL (sin escritor valido
    conocido); display_type validado de forma positiva contra NULL/working/off/holiday
    (los unicos tres valores reales del enum CalendarDisplayType, no los daily/weekly/
    monthly que traia la factory). buildSortQuery() ahora escopa sort_when_creating por
    calendar_id, antes calculaba el maximo sobre toda la tabla. RelationManager: retirados
    Restore/ForceDelete/SoftDeletingScope y sus bulk equivalentes, el modelo nunca tuvo
    SoftDeletes.
  - CalendarLeave: strict_company, sin filas compartidas (cero filas company_id NULL tras
    instalar, ningun seeder las produce). company_id y calendar_id derivados e inmutables
    tras persistir. Unico resource_type permitido: Webkul\Manufacturing\Models\WorkCenter
    (comparado contra el FQCN literal, sin morph map registrado en el proyecto); con
    resource, compania y calendario derivan de ese WorkCenter y deben coincidir con
    cualquier valor explicito recibido (incluido company_id, no solo calendar_id: hallazgo
    de la propia suite de tests de esta ola); sin resource, un Calendar de compania deriva
    su propia compania (mismo chequeo de coincidencia), un Calendar compartido exige
    company_id explicito y autorizado. Se elimino el comodin calendar_id IS NULL de
    Calendar::getLeaveIntervalsBatch(): ese comodin aplicaba cualquier ausencia sin
    calendario a TODOS los calendarios sin comparar compania, la fuga cruzada mas
    severa detectada en el analisis A4I. WorkOrder::start()/plan() ahora envian
    company_id explicito desde el WorkCenter al crear el CalendarLeave (antes lo omitian
    por completo). HolidayAction y el Select de calendario de PublicHolidayResource
    quedan scopeados automaticamente por el HasCompanyScope de Calendar/CalendarLeave, sin
    tocar esos dos archivos. CalendarLeave nunca tuvo newFactory() propio (gap
    preexistente descubierto por la propia suite nueva, corregido en la misma ola).
  - Employee.calendar_id, WorkCenter.calendar_id y Leave.calendar_id (time-off): la
    validacion generica assertRelatedBelongsToCompany() (falla cerrado ante NULL en
    cualquiera de los dos lados) fue reemplazada por un metodo local duplicado por modelo
    (assertCalendarIsAssignable(), mismo patron de duplicacion que
    ParentDerivedCompanyScope) que acepta un Calendar compartido o de la misma compania,
    rechaza uno inexistente, y rechaza una asignacion NUEVA a un Calendar soft-deleted sin
    romper una asignacion ya existente (WorkCenter.calendar() ya usaba withTrashed()).
    WorkCenter y Leave no tenian ninguna validacion sobre calendar_id antes de esta ola.
  - alias: Employee\Calendar, Employee\CalendarLeave y TimeOff\CalendarLeave son
    subclases vacias que heredan HasCompanyScope de su owner en support, el auditor las
    detecta solas (mismo patron que los cuatro alias de ActivityPlan), sin entrada de
    manifest. Employee\CalendarAttendance si necesita entrada alias explicita: su owner es
    parent_scoped, no HasCompanyScope, y no se auto-detecta.
  - manifest: 2 entradas nuevas (Support\CalendarAttendance parent_scoped,
    Employee\CalendarAttendance alias), 147 entradas totales, 0 violaciones (51 alias, 40
    global_reference, 40 parent_scoped, 12 not_tenancy, 2 multi_company_membership, 1
    global_party_identity, 1 root_company_entity)
  - inventario 139/145/20 (11+9) -> 144/147/13 (6+7): siete filas, sin altas ni bajas,
    verificado fila por fila contra el JSON regenerado (tres corridas byte a byte
    identicas sobre aureuserp_a4h_selftest, delta exacto de las siete filas predichas en
    el analisis A4I, cero colaterales)
  - suite completa: composer test 2224/2224, 5360 assertions (0 fallos; dos intentos
    previos fallaron por causas ajenas al codigo: contaminacion de permisos de archivo por
    una corrida concurrente propia en el mismo filesystem montado, y el gap preexistente
    de newFactory() en CalendarLeave, ambos corregidos antes de esta corrida final).
    CompanyScopeAuditorTest incluido y verde. Pint, composer validate --strict y git diff
    --check limpios sobre los archivos de esta ola.
  - 32 tests nuevos (CalendarCompanyScopeTest en support, mas adiciones en
    ManufacturingCompanyScopeTest, EmployeeCompanyRelationsTest y LeaveCompanyScopeTest),
    incluidos los dos controles negativos/positivos decisivos: Employee + Calendar
    compartido (antes rota por assertRelatedBelongsToCompany fallando cerrado ante NULL),
    y el regression test directo sobre getLeaveIntervalsBatch() para la fuga cruzada
  - sin dispatch de CI remoto: validacion 100% local
Correccion de revision A4I (review 4839130829, CHANGES_REQUIRED_A4I_CALENDAR_LIFECYCLE_AND_
  SHARED_BUCKET): la revision tecnica sobre el head 40f5b6ee5 encontro cinco fallas no
  cubiertas por la suite original de A4I (la marca "APROBADA" del parrafo anterior reflejaba
  la autoevaluacion de esa entrega, no una revision tecnica independiente); corregidas en esta
  ola sin tocar schema, helpers genericos ni otras familias:
  - Calendar::getLeaveIntervalsBatch(): el bucket anonimo ($resource === null) mezclaba las
    ausencias de TODAS las companias visibles para el actor (whereIn de CompanyScope), no solo
    la compania efectiva; ademas anyCalendar=true ampliaba esa mezcla incluso para un usuario
    autenticado. resolveAnonymousLeaveBucketCompanyId() ahora fija una unica compania efectiva
    (la del propio Calendar si es company-owned; default_company_id o CompanyContext::COMPANY
    si es compartido) y solo deja el bucket sin restriccion bajo ALL_COMPANIES/BOOTSTRAP
    explicito combinado con anyCalendar=true; en cualquier otro caso ambiguo el bucket queda
    vacio (falla cerrado sin romper el path de lectura con una excepcion).
  - Calendar::restoring(): a diferencia de creating/updating/deleting/forceDeleting, no
    reautorizaba la compania persistida de un Calendar company-owned (solo protegia el caso
    compartido); ahora usa CompanyScope::assertCanWriteCompany() igual que los demas hooks.
  - CalendarLeave: no tenia hook deleting (un delete cross-company via withoutGlobalScope()
    pasaba sin autorizacion); ahora reautoriza la compania persistida antes de borrar. La
    inmutabilidad de calendar_id solo comparaba cuando el valor original no era NULL, dejando
    una via para "reparar" via update ordinario una fila historica corrupta con calendar_id
    NULL (el mismo estado que el comodin removido en A4I podia producir); ahora compara sin
    excepcion, incluida la transicion NULL -> valor.
  - CalendarLeave::resolveEffectiveCompanyId() (rama resource): copiaba resource->calendar_id
    sin validarlo. resolveResourceCalendar() ahora exige que el WorkCenter tenga calendar_id,
    que ese Calendar exista, no este soft-deleted, y sea compartido o de la misma compania que
    el WorkCenter -- cierra tanto un WorkCenter sin Calendar como un emparejamiento
    WorkCenter/Calendar historicamente inconsistente (anterior a
    WorkCenter::assertCalendarIsAssignable()). Se mantuvo dentro de CalendarLeave, sin tocar
    WorkCenter.php: ninguna prueba exigio lo contrario.
  - CalendarAttendance::assertParentIsWritable(): usaba withTrashed() sin distinguir creacion
    de actualizacion/eliminacion, permitiendo crear una fila NUEVA bajo un Calendar ya
    archivado. Ahora create rechaza un padre trashed (SoftDeletes ya excluye la fila sin
    withTrashed(), no hizo falta un chequeo adicional); update/delete de una fila existente
    siguen reautorizando el padre persistido aunque haya sido archivado despues.
  - inventario sin cambios: 144/147/13 (6+7), verificado contra el JSON committeado (diff
    vacio) y dos corridas fresh byte a byte identicas
  - 13 tests nuevos (CalendarCompanyScopeTest y ManufacturingCompanyScopeTest), incluidos
    ambos controles decisivos del review: usuario con dos companias (default + allowed) y dos
    CalendarLeave reales sobre el mismo Calendar compartido; fila historica con calendar_id
    NULL simulada via insert crudo, misma tecnica que el regression test de A4I para
    getLeaveIntervalsBatch()
  - suite completa: composer test 2237/2237, 5379 assertions (0 fallos). CompanyScopeAuditorTest
    incluido y verde. Pint, composer validate --strict y git diff --check limpios sobre los
    archivos de esta ola.
  - sin dispatch de CI remoto: validacion 100% local
PR adicional para PR 4: prohibido: los cambios de negocio landean en esta misma rama/PR #18
PR 5: no autorizada
#138 / #81: abiertos
AGENTS.md: stashes intactos (ambos checkouts)
```
