# Ola 4D - propuesta de alcance

## Estado de partida

- PR #18, rama `feat/company-scope-remaining-plugins`.
- Head inicial: `8aad378c950df6a6fe5b29f49dddb9f19b62ee69`.
- 45 gaps residuales (`docs/security/company-scope-pr4-inventory.json`, `real_gap_company_column`: 25, `real_gap_without_company_column`: 20).
- Inventario: `scoped` 126, `classified_exceptions` 133, gaps reales 45.
- Investigación de soporte: agente Explore read-only mapeó los 45 FQCN contra migraciones, Filament Resources, `routes/api.php` y relaciones reales (sin cambios de código). Detalle completo abajo.

## Hallazgos transversales antes de clasificar

- **`ActivityType`** (`Support\Models\ActivityType`, alias en `recruitments` y `sales`): la tabla `activity_types` no tiene columna `company_id`. Es un catalogo global legitimo, no un gap de aislamiento. Propuesta: reclasificar en el manifest como excepcion documentada (`global_reference`), no como objetivo de ninguna ola de codigo.
- **`Recruitment\Stage`**: tabla `recruitments_stages` tampoco tiene `company_id`. Mismo patron que ActivityType (posible catalogo de pipeline global); requiere confirmacion de producto antes de decidir, no de codigo. Se deja fuera de A4D.
- **`Security\Invitation`**: ya tiene guarda de escritura propia (`CompanyScope::assertCanWriteCompany()` en creating/updating, `company_id` inmutable tras creacion), documentada desde ola 4B como contrato aprobado por diseno (sin `HasCompanyScope`, ruta de invitado via URL firmada). No tiene Resource de listado. **Correccion tras revision**: se mantiene como gap real en el inventario (no se reclasifica como `classified_exception`); la guarda de escritura existente no sustituye una clasificacion formal en el manifest. Queda fuera de A4D por bajo riesgo (no listable), no por estar resuelta.
- **Dos hallazgos que son bugs, no gaps de scope, y no deben mezclarse con ninguna ola**:
  - `Sale\OrderTemplateProduct::boot()` usa `Company::first()`/`Product::first()`/`Uom::first()` como default cuando faltan esos campos, lo que puede asignar silenciosamente una fila de OTRA compania. Requiere su propio commit de bugfix, no una ola de company-scope.
  - `Support\CurrencyRate` tiene un endpoint API real (`currencies.rates`, `CurrencyRateController@index`) que acepta `filter[company_id]` arbitrario y solo autoriza a nivel de `Currency` (no de company): un actor autenticado puede pedir explicitamente las tasas de OTRA compania por parametro de query. Es una fuga activa de datos financieros, de severidad mayor que un simple "gap sin scope". Recomendacion: tratarla como corriente de trabajo propia, priorizada por severidad, no absorbida dentro del empaquetado por dominio de A4D.
- Dos clases estan muertas (no referenciadas fuera de su propia factory): `Employee\EmployeeEmployeeCategory` y `Employee\JobPositionSkill` (las relaciones reales usan el nombre de tabla pivote directamente, no estas clases). Recomendacion: ticket de limpieza de codigo muerto, no una ola de scope.

## Clasificacion de gaps (45 filas)

| # | Plugin | FQCN | Tabla | company_id | Owner/Alias | Lectura | Escritura | Enumeracion | Mecanismo actual | Riesgo | Clasificacion propuesta | Cambio minimo | Tests | Ola |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
|1| chatter | Chatter\Attachment | chatter_attachments | si | owner | solo via tab Chatter del padre | via Message/upload | solo por id del padre | boot() sin scope | medio | real_gap (parent_scoped candidato) | HasCompanyScope o derivar de padre via ValidatesRelatedCompanyScope | lectura/escritura cross-company | futura (post padres Chatter) |
|2| chatter | Chatter\Follower | chatter_followers | no | owner | sin Resource/API | via HasChatter::addFollower() | solo por id del padre | sin scope | bajo | parent_scoped | ninguno propio, documentar dependencia del padre | regresion de HasChatter | documental |
|3| chatter | Chatter\Message | chatter_messages | si | owner | tab Chatter del padre | via HasChatter | solo por id del padre | boot() sin scope | medio | real_gap (parent_scoped candidato) | igual que Attachment | lectura/escritura cross-company | futura (post padres Chatter) |
|4| employees | Employee\Calendar | calendars | si | **alias** de Support\Calendar | via Employee->calendar() | via form Employee | bajo directo, alto via CalendarResource del owner | ninguno propio | alto (via owner) | alias | ninguno; se resuelve escopando Support\Calendar | herencia via late static binding | **4D (alternativa)** |
|5| employees | Employee\CalendarAttendance | calendar_attendances | no | alias de Support\CalendarAttendance | RelationManager de CalendarResource | via RelationManager | solo por id de Calendar | ninguno propio | medio | alias | se resuelve escopando el owner | herencia | **4D (alternativa)** |
|6| employees | Employee\CalendarLeave | calendar_leaves | si | alias de Support\CalendarLeave | ninguno propio en employees | n/a | via alias TimeOff con Resource propio | ninguno propio | alto (via alias TimeOff) | alias | se resuelve escopando el owner | herencia | **4D (alternativa)** |
|7| employees | Employee\Department | employees_departments | si | **owner** | `DepartmentResource` (List completo) | Resource form | **directa, listable** | boot() sin scope | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura/reasignacion cross-company | **4D (principal)** |
|8| employees | Employee\Employee | employees_employees | si | **owner** | `EmployeeResource` (List completo, PII: salario/direccion/telefono/fecha nacimiento) | Resource form | **directa, listable, PII** | boot() valida bank account, sin CompanyScope | **critico** | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura/relaciones (Partner auto-creado) | **4D (principal)** |
|9| employees | Employee\EmployeeEmployeeCategory | employees_employee_categories | no | owner (pivot) | ninguna, clase no referenciada | n/a | n/a | sin scope | bajo | dead_code | eliminar o documentar como muerto | ninguno (no ejercitada) | documental (no es ola) |
|10| employees | Employee\EmployeeJobPosition | employees_job_positions | si | **owner** | `JobPositionResource` (Configurations) | Resource form | **directa, listable** | sin scope | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura/reasignacion | **4D (principal)** |
|11| employees | Employee\EmployeeResume | employees_employee_resumes | no | owner | RelationManager de EmployeeResource | via RelationManager | solo por id de Employee | boot() creator_id | medio | parent_scoped (tras 4D) | ninguno propio si Employee queda scoped | regresion de RelationManager | 4D (se resuelve por transitividad, sin cambio propio) |
|12| employees | Employee\EmployeeSkill | employees_employee_skills | no | **owner** | `EmployeeSkillResource` (Reportings, **listado global buscable por empleado/skill**) + RelationManager | ambos | **directa, cross-company buscable** | boot() creator_id | alto | real_gap (parent_scoped tras correccion) | **corregido tras revision**: sin `company_id` propio, no aplica `HasStrictCompanyId` ni migracion nueva. Lectura: scope de solo-lectura derivado de `employee()` (filtra por `allowedCompanyIds()` del actor via `whereHas('employee', ...)`, mismo principio que `BankAccountCompanyMembershipScope` pero contra una relacion en vez de un pivote). Escritura: `resolveEffectiveCompanyIdOrFail()` (`ValidatesRelatedCompanyScope`, mismo mecanismo que `Task::resolveEffectiveCompanyIdOrFail($task->project_id, Project::class, ...)`) validando contra `Employee` como padre obligatorio. Precedente directo en el mismo plugin: `Recruitment\CandidateSkill` ya sigue este patron parent-derived sin `company_id` propio | lectura cross-company via Resource global; escritura con parent_id falsificado | **4D (principal)** |
|13| employees | Employee\JobPositionSkill | job_position_skills | no | owner (pivot) | ninguna, clase no referenciada | n/a | n/a | sin scope | bajo | dead_code | eliminar o documentar como muerto | ninguno | documental (no es ola) |
|14| employees | Employee\WorkLocation | employees_work_locations | si | **owner** | `WorkLocationResource` (Configurations) | Resource form | directa, listable | boot() creator_id + scopeActive() | medio | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura | **4D (principal)** |
|15| payments | Payment\PaymentToken | payments_payment_tokens | si | owner | ninguna, solo via Account\Payment->paymentToken() | via lo que crea Payment | solo por id del padre | sin scope | medio | parent_scoped | derivar de Payment (ValidatesRelatedCompanyScope) | escritura directa hipotetica | futura (plugin payments fuera del runner canonico) |
|16| payments | Payment\PaymentTransaction | payments_payment_transactions | si | owner | ninguna, solo via Payment | igual | solo por id del padre | sin scope | medio | parent_scoped | igual | igual | futura (plugin payments fuera del runner canonico) |
|17| recruitments | Recruitment\ActivityType | activity_types | no | alias de Support\ActivityType | catalogo compartido | n/a | n/a (catalogo global) | sin scope, tabla sin company_id | bajo | reclasificar global_reference | ninguno | ninguno | documental (reclasificacion de manifest) |
|18| recruitments | Recruitment\Applicant | recruitments_applicants | si | **owner** | `ApplicantResource` (List completo, PII salarial) | Resource form + kanban | **directa, listable, PII** | metodos de creacion no enganchados a boot(), sin scope | **critico** | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura/kanban drag | fuera de A4D (dominio recruitment, futura ola dedicada) |
|19| recruitments | Recruitment\ApplicantApplicantCategory | recruitments_applicant_applicant_categories | no | owner (pivot) | ninguna | via form Applicant | pivot | sin scope | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|20| recruitments | Recruitment\ApplicantInterviewer | recruitments_applicant_interviewers | no | owner (pivot) | ninguna | via form Applicant | pivot | sin scope | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|21| recruitments | Recruitment\Candidate | recruitments_candidates | si | **owner** | `CandidateResource` (List completo, PII: email/telefono/LinkedIn/salario esperado) | Resource form | **directa, listable, PII** | boot() crea/actualiza Partner, sin scope | **critico** | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura/Partner sync | fuera de A4D (dominio recruitment, futura ola dedicada) |
|22| recruitments | Recruitment\CandidateApplicantCategory | recruitments_candidate_applicant_categories | no | owner (pivot) | ninguna | via form Candidate | pivot | sin scope | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|23| recruitments | Recruitment\CandidateSkill | recruitments_candidate_skills | no | owner | ninguna standalone, via Candidate/Applicant->skills() | via relacion | solo por id | boot() creator_id | medio | parent_scoped | ninguno propio si Candidate queda scoped | ninguno | fuera de A4D |
|24| recruitments | Recruitment\Department | employees_departments | si | **alias** de Employee\Department, con Resource propia adicional | `DepartmentResource` propia (Configurations) | Resource form propia | **directa, listable (superficie redundante)** | sin scope propio | alto | alias | se resuelve escopando el owner; verificar que su Resource no use withoutGlobalScope() | verificar Resource alias tras escopar owner | **4D (principal, transitivo)** |
|25| recruitments | Recruitment\JobByPosition | employees_job_positions | si | alias de Recruitment\JobPosition (2 niveles desde el owner) | `JobByPositionResource` propia (Applications) | Resource form propia | directa, listable (redundante) | boot() propio solo invalida cache | alto | alias | se resuelve escopando el owner; verificar Resource | verificar Resource alias | **4D (principal, transitivo)** |
|26| recruitments | Recruitment\JobPosition | employees_job_positions | si | alias-con-logica de Employee\EmployeeJobPosition | `JobPositionResource` propia (Configurations) | Resource form propia | directa, listable (redundante) | boot() propio solo invalida cache | alto | alias | se resuelve escopando el owner; verificar Resource | verificar Resource alias | **4D (principal, transitivo)** |
|27| recruitments | Recruitment\JobPositionInterviewer | recruitments_job_position_interviewers | no | owner (pivot) | ninguna | via form JobPosition | pivot | sin scope | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|28| recruitments | Recruitment\Stage | recruitments_stages | no | owner | `StageResource` (List) | Resource form | directa, listable, pero tabla sin columna company_id | sin scope, sin columna company_id | bajo | pendiente confirmacion de producto (posible catalogo global) | ninguno hasta confirmar | ninguno | documental (requiere decision de producto, no de codigo) |
|29| recruitments | Recruitment\StageJob | recruitments_stages_jobs | no | owner (pivot) | ninguna | via form Stage | pivot | sin scope | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|30| sales | Sale\ActivityType | activity_types | no | alias de Support\ActivityType | catalogo compartido | n/a | n/a | sin scope, tabla sin company_id | bajo | reclasificar global_reference | ninguno | ninguno | documental (reclasificacion de manifest) |
|31| sales | Sale\AdvancedPaymentInvoice | sales_advance_payment_invoices | si | owner | sin Resource, solo via wizard de Order (SaleManager) | via ese wizard | solo alcanzable via Order | boot() creator_id | medio | parent_scoped | derivar de Order | regresion del wizard | fuera de A4D |
|32| sales | Sale\AdvancedPaymentInvoiceOrderSale | sales_advance_payment_invoice_order_sales | no | owner (pivot) | ninguna | via servicio anterior | pivot | sin scope | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|33| sales | Sale\OrderOption | sales_order_options | no | owner | ninguna, via Order->orderOptions() | via form Order | solo por id de Order | boot() creator_id | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|34| sales | Sale\OrderTemplate | sales_order_templates | si | owner | `QuotationTemplateResource` (List, pendiente confirmar ubicacion de clase base) | Resource form | directa, listable | boot() creator_id | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura | fuera de A4D (dominio sales, futura ola) |
|35| sales | Sale\OrderTemplateProduct | sales_order_template_products | si | owner | via OrderTemplate (repeater) | via form OrderTemplate | solo por id de OrderTemplate | **boot() usa `::first()` de Company/Product/Uom como default (bug de asignacion cross-tenant, no solo gap de scope)** | alto (bug) | bug_ticket + parent_scoped | fix del default `::first()` es un commit de bug independiente | test de no-fallback a otra compania | bug ticket separado, no ola |
|36| sales | Sale\Tag | sales_tags | no | owner | `TagResource` (List) **y** `apiResource('tags', TagController)` sin restriccion (index/show/store/update/destroy abiertos) | Resource form + API completa | **directa, listable y API paginable** | boot() creator_id | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura via Resource y API | fuera de A4D (dominio sales, futura ola) |
|37| sales | Sale\Team | sales_teams | si | owner | `TeamResource` (List completo) | Resource form | directa, listable | boot() creator_id | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura | fuera de A4D (dominio sales, futura ola) |
|38| sales | Sale\TeamMember | sales_team_members | no | owner (pivot) | ninguna, via Team->members() | via form Team | pivot | sin scope | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|39| security | Security\Invitation | user_invitations | si | owner | ninguna (solo header action de UserResource) | header action + aceptacion via URL firmada | no listable | **guarda de escritura propia ya implementada (ola 4B): assertCanWriteCompany(), company_id inmutable** | bajo | **real_gap (mantenido, no se reclasifica)** | ninguno en A4D | ninguno en A4D | fuera de A4D (gap real de bajo riesgo, diferido) |
|40| support | Support\Calendar | calendars | si | **owner** | `CalendarResource` (List completo + RelationManager de attendance) | Resource form | **directa, listable** | boot() creator_id, sin scope | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura, cascada a 3 alias | **4D (alternativa)** |
|41| support | Support\CalendarAttendance | calendar_attendances | no | owner | solo via RelationManager de CalendarResource | via RelationManager | solo por id de Calendar | boot() creator_id | medio | real_gap (parent_scoped candidato) | HasCompanyScope o derivar del padre | via RelationManager | **4D (alternativa)** |
|42| support | Support\CalendarLeave | calendar_leaves | si | **owner** | ninguna propia en support (ver alias TimeOff) | n/a directo | via alias TimeOff con Resource propia y filtro company_id expuesto | boot() creator_id + company_id de Auth | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura, cascada a alias TimeOff | **4D (alternativa)** |
|43| support | Support\CurrencyRate | currency_rates | si | owner | sin Resource, pero **API `currencies.rates` con filtro `filter[company_id]` explicito, autorizado solo a nivel de Currency** | misma API (store/update/destroy) | **API paginable, cross-company por diseno del query param** | Gate::authorize('view', $currency), no company-scoped | **critico (fuga activa via API)** | real_gap | HasCompanyScope + IncludesSharedCompanyRows (`company_id` ya nullable, sin migracion), mas cerrar el filtro `company_id` arbitrario y las validaciones de escritura en el controller (contrato completo en la seccion `A4D-0` arriba) | lectura/escritura API cross-company, filas compartidas `company_id = null` | **A4D-0 (hotfix previo, no forma parte del empaquetado por dominio de A4D)** |
|44| support | Support\UtmCampaign | utm_campaigns | si | owner | sin Resource ni API, solo via Order/Move por id | via esos padres | solo por id del padre | boot() creator_id | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|45| time-off | TimeOff\CalendarLeave | calendar_leaves | si | **alias** de Support\CalendarLeave, con Resource propia (`PublicHolidayResource`) | **Resource propia con filtros `company_id` y `creator_id` expuestos en la UI** | Resource form propia | **directa, listable, con selector cross-company explicito** | sin scope propio | alto | alias | se resuelve escopando el owner; verificar que el filtro `company_id` de la UI no permita bypass | verificar filtro tras escopar owner | **4D (alternativa)** |

## Matriz de relaciones same-company (Employee / Department / EmployeeJobPosition / aliases)

Investigacion read-only (agente Explore, lectura directa de modelos y migraciones) de cada relacion `belongsTo` cuyo modelo relacionado ya tiene o tendra `company_id` propio, para especificar exactamente que debe validarse con `ValidatesRelatedCompanyScope`/`HasStrictCompanyId` al escopar esta familia (mismo patron que `Task.stage_id`/`Task.parent_id` en ola 4A):

| Modelo | Relacion | FK | Relacionado | company_id en relacionado | Validacion requerida |
|---|---|---|---|---|---|
| `Employee\Employee` | `department()` | `department_id` | `Employee\Department` | si (nullable) | mismo `company_id` que el Employee, o rechazar |
| `Employee\Employee` | `job()` | `job_id` | `Employee\EmployeeJobPosition` | si (nullable) | mismo `company_id` que el Employee, o rechazar |
| `Employee\Employee` | `workLocation()` | `work_location_id` | `Employee\WorkLocation` | si | mismo `company_id` que el Employee, o rechazar |
| `Employee\Employee` | `parent()` (jefe directo) | `parent_id` | self (`Employee`) | si | mismo `company_id`, y excluir auto-referencia |
| `Employee\Employee` | `coach()` | `coach_id` | self (`Employee`) | si | mismo `company_id` |
| `Employee\Employee` | `bankAccount()` | `bank_account_id` | `Partner\BankAccount` | pivote (ola 4C) | ya gobernado por `assertEnabledForCompany()` (`Employee.php`, boot `saving`); no requiere cambio nuevo, solo confirmar que sigue funcionando bajo `HasCompanyScope` del Employee |
| `Employee\Department` | `parent()` | `parent_id` | self (`Department`) | si | mismo `company_id`; ya existe guard de recursion (`validateNoRecursion()`), extender para exigir tambien mismo `company_id`, no solo ausencia de ciclos |
| `Employee\Department` | `masterDepartment()` | `master_department_id` | self (`Department`) | si | mismo `company_id` |
| `Employee\Department` | `manager()` | `manager_id` | `Employee\Employee` | si | mismo `company_id` que el Department |
| `Employee\EmployeeJobPosition` | `department()` | `department_id` | `Employee\Department` | si | mismo `company_id` que la JobPosition |
| `Recruitment\JobPosition` (alias con logica propia) | `manager()` (relacion propia, no heredada) | `manager_id` | `Employee\Employee` | si | mismo `company_id` que la JobPosition; **este metodo es codigo propio del alias, no heredado, por lo que requiere su propia validacion aunque el scope de lectura se herede por late static binding** |
| `Recruitment\JobPosition` | `address()` (relacion propia) | `address_id` | `Partner\Partner` (`sub_type=company`) | no aplica (Partner es `global_party_identity`) | ninguna, Partner es identidad global por diseno existente |

Nota: las 3 relaciones propias de `Recruitment\JobPosition` (`address()`, `manager()`, `interviewers()`/`recruiter()`/`industry()`) son codigo que el alias agrega por encima del padre (`Employee\EmployeeJobPosition`), confirmado via lectura directa del archivo; el resto de la familia (`Recruitment\Department`, `Recruitment\JobByPosition`) son extensiones vacias sin logica ni relaciones propias, y heredan integramente el contrato del owner sin necesitar validacion adicional propia.

## A4D-0: CurrencyRate security hotfix

Etapa previa a la ola `employees`, priorizada por severidad (fuga activa de datos financieros via API, no solo ausencia de scope). No se implementa junto con la familia `employees` para no mezclar dominios ni ocultar su urgencia dentro de un paquete mas grande.

Hechos verificados (lectura directa de `Support\CurrencyRate`, `CurrencyRateController`, `CurrencyRateRequest`, `support/routes/api.php`):

- `company_id` es nullable: `NULL` = tasa compartida/global, valor = tasa especifica de una compania.
- `index()`: `QueryBuilder::for(CurrencyRate::where('currency_id', $currency))->allowedFilters(AllowedFilter::exact('company_id'), ...)`, autorizado solo con `Gate::authorize('view', $currency)` (a nivel de `Currency`, nunca de `company_id`). El filtro `company_id` acepta cualquier valor sin restriccion, y sin filtro devuelve TODAS las filas de TODAS las companias.
- `show()`/`update()`/`destroy()`: obtienen la fila por `id` + `currency_id`, nunca comparan `company_id` contra el actor. Un actor autorizado a nivel de `Currency` puede leer/editar/borrar la tasa de cualquier compania por id.
- `store()`/`update()`: `CurrencyRateRequest` valida `company_id` solo como `nullable|integer|exists:companies,id`, sin restringirlo a las companias permitidas del actor.

Contrato del hotfix:

- **Lectura company-or-shared**: un actor ve las filas con `company_id IS NULL` (compartidas) mas las filas cuyo `company_id` este en `CompanyScope::allowedCompanyIds()`. Mismo principio que `IncludesSharedCompanyRows` (ya usado en ola 4B para `ActivityPlan`/`ProjectStage`), aplicado aqui sobre `CurrencyRate`.
- **Restriccion del filtro `company_id`**: `AllowedFilter::exact('company_id')` debe interseccionarse con `allowedCompanyIds() ∪ {null}`; un valor fuera de ese conjunto no debe filtrar hacia datos ajenos (ignorar el filtro invalido o devolver vacio, nunca las filas de la compania solicitada).
- **`index`/`show`/`update`/`destroy` aislados**: cada handler debe verificar que la fila objetivo tenga `company_id IS NULL` o pertenezca a una compania permitida antes de devolverla/mutarla; fuera de ese conjunto, `404` (no `403`, para no confirmar existencia de filas ajenas, mismo patron ya usado en `BankAccountReadIntegrationTest` de ola 4C).
- **Creacion y actualizacion autorizadas**: `store`/`update` deben validar que el `company_id` recibido (si no es `null`) pertenezca a las companias permitidas del actor, equivalente a `CompanyScope::assertCanWriteCompany()`.
- **Tratamiento explicito de `company_id = null`**: las filas compartidas son legibles por cualquier actor autenticado con acceso a la `Currency`, pero solo mutables por `super_admin` o proceso de sistema, mismo patron de `guardSharedRowMutation()` ya usado para filas `company_id IS NULL` en `ProjectStage` (ola 4B).
- **Tests API requeridos**: actor de compania A no ve/edita/borra una tasa de compania B (index/show/update/destroy, los 4 verbos); actor de compania A y actor de compania B ven ambos la misma fila compartida (`company_id = null`); un actor sin companias permitidas ve solo las filas compartidas (lista no vacia si existen, pero ninguna especifica de compania); intento de escritura de un no-`super_admin` sobre una fila compartida es rechazado.

Cambio de codigo esperado: `Support\CurrencyRate` (agregar `HasCompanyScope` + `IncludesSharedCompanyRows`, sin nueva migracion ya que `company_id` ya existe y ya es nullable) y `CurrencyRateController`/`CurrencyRateRequest` (cerrar el filtro abierto y las validaciones de escritura). No se implementa en esta ronda: este documento solo especifica el contrato, la implementacion queda para su propia autorizacion.

## Ranking de riesgo

- **Critico**: `Employee\Employee` (8), `Recruitment\Applicant` (18), `Recruitment\Candidate` (21), `Support\CurrencyRate` (43, fuga activa via API, tratar como fix de severidad propio).
- **Alto**: `Employee\Department` (7) + alias `Recruitment\Department` (24), `Employee\EmployeeJobPosition` (10) + alias `Recruitment\JobPosition`/`JobByPosition` (25, 26), `Employee\EmployeeSkill` (12), `Support\Calendar` (40) + alias `Employee\Calendar` (4) + alias `TimeOff\CalendarLeave` (45) + owner `Support\CalendarLeave` (42) + alias `Employee\CalendarLeave` (6), `Sale\OrderTemplate` (34), `Sale\Tag` (36), `Sale\Team` (37).
- **Medio**: `Chatter\Attachment`/`Message` (1, 3), `Employee\CalendarAttendance` (5) + owner `Support\CalendarAttendance` (41), `Employee\EmployeeResume` (11), `Employee\WorkLocation` (14), `Payment\PaymentToken`/`PaymentTransaction` (15, 16), `Recruitment\CandidateSkill` (23), `Sale\AdvancedPaymentInvoice` (31), `Sale\OrderTemplateProduct` (35, ademas bug).
- **Bajo/documental**: todos los pivotes puros (9, 13, 19, 20, 22, 27, 29, 32, 33, 38), `Chatter\Follower` (2), `ActivityType` x2 (17, 30), `Recruitment\Stage` (28), `Security\Invitation` (39), `Support\UtmCampaign` (44).

## Propuesta principal A4D

**Familia**: nucleo de `employees` (owner unico de cada modelo, sin dependencia de nueva taxonomia ni de CompanyScope generico).

FQCN exactos a modificar (5, todos ya usan o pueden usar `HasCompanyScope` + `HasStrictCompanyId`, patron identico a ola 4A/4B):

1. `Webkul\Employee\Models\Employee` (fila 8, critico, PII) - `HasCompanyScope` + `HasStrictCompanyId`.
2. `Webkul\Employee\Models\Department` (fila 7, alto; cierra transitivamente fila 24, `Recruitment\Department`) - `HasCompanyScope` + `HasStrictCompanyId`, mas extender `validateNoRecursion()` para exigir tambien mismo `company_id` en `parent_id`/`master_department_id` (ver matriz de relaciones arriba).
3. `Webkul\Employee\Models\EmployeeJobPosition` (fila 10, alto; cierra transitivamente filas 25 y 26, `Recruitment\JobPosition`/`JobByPosition`) - `HasCompanyScope` + `HasStrictCompanyId`.
4. `Webkul\Employee\Models\WorkLocation` (fila 14, medio) - `HasCompanyScope` + `HasStrictCompanyId`.
5. `Webkul\Employee\Models\EmployeeSkill` (fila 12, alto, Resource global buscable cross-company) - **sin `HasStrictCompanyId`, sin migracion**: scope de lectura parent-derived desde `employee()` mas `resolveEffectiveCompanyIdOrFail()` en escritura (ver correccion en la tabla de clasificacion arriba).

Ademas, `Recruitment\JobPosition` (alias con logica propia) requiere su propia validacion de `manager_id` contra el mismo `company_id`, ya que esa relacion es codigo propio del alias, no heredado (ver matriz de relaciones).

Archivos estimados (sin contar tests): 4 modelos con `HasStrictCompanyId` + 1 modelo con scope parent-derived (`EmployeeSkill`) + 1 archivo de manifest (`config/company-scope-exceptions.php`, para los 3 alias de Recruitment y para `EmployeeSkill` como `parent_scoped`) + regeneracion de `docs/security/company-scope-pr4-inventory.json`. Sin nuevas migraciones.

Contrato de aislamiento: identico al usado en ola 4A/4B (`HasCompanyScope` + `HasStrictCompanyId`) para los 4 owners con `company_id` propio; los 3 alias de Recruitment heredan por late static binding, igual que se verifico empiricamente para BankAccount en ola 4C. `EmployeeSkill` usa un mecanismo distinto (parent-derived, ver arriba) precisamente porque no tiene columna `company_id` propia. Antes de cerrar, verificar explicitamente que `DepartmentResource`, `JobPositionResource` y `JobByPositionResource` (las 3 Resources independientes de `recruitments` sobre las mismas tablas) no llamen `withoutGlobalScope()` en ningun punto.

Pruebas: siguiendo el patron de ola 4A/4B, minimo por modelo con `HasStrictCompanyId`: lectura misma compania, lectura oculta de otra compania, creacion/actualizacion cross-company rechazada, reasignacion rechazada donde aplique (incluida la matriz completa de relaciones same-company arriba), `CompanyContext::runForCompany/runForAllCompanies/runForBootstrap`. Para los 3 alias de Recruitment: un test directo de herencia por clase (mismo patron que los 3 tests de alias de BankAccount en ola 4C) mas una verificacion de que su Resource propia no enumera filas ocultas, mas el test especifico de `manager_id` en `Recruitment\JobPosition`. Para `EmployeeSkill`: lectura filtrada por companias del empleado, escritura rechazada si el `employee_id` referenciado no resuelve a una compania permitida (test analogo a `Task::resolveEffectiveCompanyIdOrFail` en ola 4A).

Conteos esperados del inventario (estimado, a confirmar con el auditor real tras implementar, no asumido de antemano; **corregido tras revision para no tratar `EmployeeSkill` como owner strict-company y para separar el efecto de A4D-0**):

- Punto de partida de esta ola (tras A4D-0, ver seccion propia arriba): `scoped` 127, `classified_exceptions` 133, gaps 44.
- Tras la ola `employees`: `scoped` 127 -> 131 (+4: `Employee`, `Department`, `EmployeeJobPosition`, `WorkLocation`, los unicos con `HasStrictCompanyId` real). `classified_exceptions` 133 -> 137 (+4: los 3 alias de Recruitment clasificados `alias`, mas `EmployeeSkill` clasificado `parent_scoped`, mismo patron que `BankAccount` en ola 4C no incrementa `scoped` al usar un mecanismo distinto de `HasCompanyScope`). Gaps reales 44 -> 36 (-8: filas 7, 8, 10, 14, 24, 25, 26, 12).
- `Invitation` (39) y `Recruitment\Stage` (28) no se descuentan: siguen contando entre los gaps residuales tras esta ola.

Riesgos de regresion: `Employee` tiene logica de `boot()` que valida bank account y crea/actualiza un `Partner` asociado; anadir `HasCompanyScope` requiere confirmar que esa creacion de Partner sigue funcionando bajo los mismos `CompanyContext` usados en ola 4A/4B (patron ya resuelto para Candidate/Recruitment en ola anterior, referencia directa disponible). `EmployeeSkill` tiene una Resource global: el scope parent-derived debe filtrar por `allowedCompanyIds()` del actor via la relacion `employee()`, y confirmar que el listado sigue siendo util para un actor multi-compania (no queda vacio por error de precedencia) y que rechaza un `employee_id` de otra compania en creacion/actualizacion.

## Alternativa secundaria

**Familia**: `calendars`/`calendar_attendances`/`calendar_leaves` (owner unico en `support`, tres platicas de alias).

FQCN a modificar (3, todos en `support`):

1. `Webkul\Support\Models\Calendar` (fila 40, alto).
2. `Webkul\Support\Models\CalendarAttendance` (fila 41, medio).
3. `Webkul\Support\Models\CalendarLeave` (fila 42, alto).

Cierra transitivamente 4 alias sin cambio de codigo propio: `Employee\Calendar` (4), `Employee\CalendarAttendance` (5), `Employee\CalendarLeave` (6), `TimeOff\CalendarLeave` (45, cuya Resource propia `PublicHolidayResource` expone un filtro `company_id` en la UI, hoy sin scope real detras).

Ventaja: 3 cambios de codigo cierran 7 filas del inventario (mayor eficiencia por FQCN que la propuesta principal). Desventaja frente a la principal: no cubre ningun modelo con PII critica (Employee/Candidate/Applicant quedan sin tocar), y el filtro `company_id` expuesto en `PublicHolidayResource` requiere verificacion adicional de que no permita bypass una vez escopado el owner.

Recomendacion: si el presupuesto de la ola solo permite una familia, priorizar la principal (PII critica). Si permite dos, esta alternativa es la siguiente de mayor eficiencia y menor riesgo de regresion (ningun `boot()` de los 3 owners tiene logica de negocio compleja mas alla de `creator_id`).

## Fuera de A4D

- **Recruitment (Candidate/Applicant/CandidateSkill y pivotes asociados)**: PII critica equivalente a Employee, pero es un dominio propio (contratacion, no nomina); mezclar con la familia `employees` violaria la regla de no combinar dominios en la misma ola. Candidato natural para una ola 4E dedicada.
- **Sales (Tag/Team/OrderTemplate y pivotes)**: `Tag` tiene ademas una API REST abierta sin restriccion; dominio comercial propio, se recomienda una ola dedicada.
- **Chatter (Attachment/Follower/Message)**: dependen de que el modelo padre (que las usa via `HasChatter`) ya este scoped; mejor resueltas cuando se aborde cada dominio padre, no como ola independiente.
- **Payments (PaymentToken/PaymentTransaction)**: alcanzables solo via `Account\Payment`, y el plugin `payments` sigue fuera del runner canonico (`phpunit.xml`); no iniciar sin antes decidir si se amplia el runner (decision separada, fuera de alcance de una ola de scope).
- **`ActivityType` (recruitments/sales)**: reclasificacion de manifest, no trabajo de codigo (tabla sin `company_id`, catalogo global legitimo).
- **`Recruitment\Stage`**: **mantenido pendiente de decision de producto**, no reclasificado; tabla sin `company_id`, pero requiere confirmacion explicita de que es un catalogo global antes de tocar su clasificacion o su codigo.
- **`Security\Invitation`**: **mantenido como gap real** (no se reclasifica como `classified_exception`), diferido por su bajo riesgo de enumeracion, no por estar resuelto.
- **Backfill historico de BankAccount** (deuda declarada desde ola 4B, `A4B-AUD-007`): sigue diferido, no forma parte de A4D.
- **Ampliacion general del runner canonico** (`payments`, `plugin-manager`, `products`, `recruitments` fuera de `phpunit.xml`): decision independiente de infraestructura de tests, no debe mezclarse con ninguna ola de scope.
- **`OrderTemplateProduct::boot()` (default `::first()`)**: bug de severidad propia, commit de fix independiente, no absorbido por el empaquetado de una ola.
- **Delete/restore sin reautorizacion general para filas no compartidas** (deuda declarada desde ola 4B, `A4B-AUD-005`): sigue diferida, transversal a multiples modelos ya scoped, no es parte de A4D.

Nota: `Support\CurrencyRate` ya NO esta en "fuera de alcance" sin mas - tiene su propia etapa previa especificada arriba (`A4D-0: CurrencyRate security hotfix`), justamente por no merecer esperar el turno normal de empaquetado por dominio.

## Recomendacion final

Secuenciar en dos etapas, no una sola ola:

1. **`A4D-0` (CurrencyRate security hotfix, ver seccion propia arriba)**: fuga activa de datos financieros via API explotable hoy; se prioriza antes que cualquier empaquetado por dominio, precisamente por su severidad. No se implementa junto con `employees` para no diluir su urgencia dentro de un paquete mas grande ni mezclar dominios.
2. **`A4D` (familia `employees`)**: `Employee`, `Department`, `EmployeeJobPosition`, `WorkLocation` con `HasCompanyScope` + `HasStrictCompanyId` y la matriz completa de relaciones same-company especificada arriba; `EmployeeSkill` con el contrato parent-derived corregido (sin `HasStrictCompanyId`, sin migracion). Cubre el mayor riesgo real restante (PII de empleados directamente listable sin scope) y sigue el mismo patron ya probado en 4A/4B/4C, sin introducir cambios transversales de `CompanyScope`, taxonomia nueva, backfill ni ampliacion de runner.

`ActivityType` (2 alias) puede reclasificarse en el manifest de forma puramente documental en cualquier momento (no depende de ninguna ola de codigo). `Recruitment\Stage` y `Security\Invitation` se mantienen sin cambio de clasificacion: el primero pendiente de una decision de producto explicita, el segundo como gap real de bajo riesgo diferido. Ninguno de los tres se descuenta del conteo de gaps residuales hasta que exista una decision o implementacion real que lo justifique. El bug de `OrderTemplateProduct::boot()` se registra como hallazgo de severidad independiente, con su propio commit de fix, fuera de cualquier ola de scope.
