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
- **`Security\Invitation`**: ya tiene guarda de escritura propia (`CompanyScope::assertCanWriteCompany()` en creating/updating, `company_id` inmutable tras creacion), documentada desde ola 4B como contrato aprobado por diseno (sin `HasCompanyScope`, ruta de invitado via URL firmada). No tiene Resource de listado. Recomendacion: reclasificar como `classified_exception` en vez de mantenerla como gap real; no requiere trabajo de codigo en A4D.
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
|12| employees | Employee\EmployeeSkill | employees_employee_skills | no | **owner** | `EmployeeSkillResource` (Reportings, **listado global buscable por empleado/skill**) + RelationManager | ambos | **directa, cross-company buscable** | boot() creator_id | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura cross-company via Resource global | **4D (principal)** |
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
|39| security | Security\Invitation | user_invitations | si | owner | ninguna (solo header action de UserResource) | header action + aceptacion via URL firmada | no listable | **guarda de escritura propia ya implementada (ola 4B): assertCanWriteCompany(), company_id inmutable** | bajo | reclasificar classified_exception | ninguno | ninguno | documental (reclasificacion, no codigo) |
|40| support | Support\Calendar | calendars | si | **owner** | `CalendarResource` (List completo + RelationManager de attendance) | Resource form | **directa, listable** | boot() creator_id, sin scope | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura, cascada a 3 alias | **4D (alternativa)** |
|41| support | Support\CalendarAttendance | calendar_attendances | no | owner | solo via RelationManager de CalendarResource | via RelationManager | solo por id de Calendar | boot() creator_id | medio | real_gap (parent_scoped candidato) | HasCompanyScope o derivar del padre | via RelationManager | **4D (alternativa)** |
|42| support | Support\CalendarLeave | calendar_leaves | si | **owner** | ninguna propia en support (ver alias TimeOff) | n/a directo | via alias TimeOff con Resource propia y filtro company_id expuesto | boot() creator_id + company_id de Auth | alto | real_gap | HasCompanyScope + HasStrictCompanyId | lectura/escritura, cascada a alias TimeOff | **4D (alternativa)** |
|43| support | Support\CurrencyRate | currency_rates | si | owner | sin Resource, pero **API `currencies.rates` con filtro `filter[company_id]` explicito, autorizado solo a nivel de Currency** | misma API (store/update/destroy) | **API paginable, cross-company por diseno del query param** | Gate::authorize('view', $currency), no company-scoped | **critico (fuga activa via API)** | real_gap | HasCompanyScope + HasStrictCompanyId, mas cerrar el filtro `company_id` arbitrario en el controller | lectura/escritura API cross-company | bug/fix ticket propio, priorizado por severidad, fuera del empaquetado por dominio de A4D |
|44| support | Support\UtmCampaign | utm_campaigns | si | owner | sin Resource ni API, solo via Order/Move por id | via esos padres | solo por id del padre | boot() creator_id | bajo | parent_scoped | ninguno propio | ninguno | fuera de A4D |
|45| time-off | TimeOff\CalendarLeave | calendar_leaves | si | **alias** de Support\CalendarLeave, con Resource propia (`PublicHolidayResource`) | **Resource propia con filtros `company_id` y `creator_id` expuestos en la UI** | Resource form propia | **directa, listable, con selector cross-company explicito** | sin scope propio | alto | alias | se resuelve escopando el owner; verificar que el filtro `company_id` de la UI no permita bypass | verificar filtro tras escopar owner | **4D (alternativa)** |

## Ranking de riesgo

- **Critico**: `Employee\Employee` (8), `Recruitment\Applicant` (18), `Recruitment\Candidate` (21), `Support\CurrencyRate` (43, fuga activa via API, tratar como fix de severidad propio).
- **Alto**: `Employee\Department` (7) + alias `Recruitment\Department` (24), `Employee\EmployeeJobPosition` (10) + alias `Recruitment\JobPosition`/`JobByPosition` (25, 26), `Employee\EmployeeSkill` (12), `Support\Calendar` (40) + alias `Employee\Calendar` (4) + alias `TimeOff\CalendarLeave` (45) + owner `Support\CalendarLeave` (42) + alias `Employee\CalendarLeave` (6), `Sale\OrderTemplate` (34), `Sale\Tag` (36), `Sale\Team` (37).
- **Medio**: `Chatter\Attachment`/`Message` (1, 3), `Employee\CalendarAttendance` (5) + owner `Support\CalendarAttendance` (41), `Employee\EmployeeResume` (11), `Employee\WorkLocation` (14), `Payment\PaymentToken`/`PaymentTransaction` (15, 16), `Recruitment\CandidateSkill` (23), `Sale\AdvancedPaymentInvoice` (31), `Sale\OrderTemplateProduct` (35, ademas bug).
- **Bajo/documental**: todos los pivotes puros (9, 13, 19, 20, 22, 27, 29, 32, 33, 38), `Chatter\Follower` (2), `ActivityType` x2 (17, 30), `Recruitment\Stage` (28), `Security\Invitation` (39), `Support\UtmCampaign` (44).

## Propuesta principal A4D

**Familia**: nucleo de `employees` (owner unico de cada modelo, sin dependencia de nueva taxonomia ni de CompanyScope generico).

FQCN exactos a modificar (5, todos ya usan o pueden usar `HasCompanyScope` + `HasStrictCompanyId`, patron identico a ola 4A/4B):

1. `Webkul\Employee\Models\Employee` (fila 8, critico, PII).
2. `Webkul\Employee\Models\Department` (fila 7, alto; cierra transitivamente fila 24, `Recruitment\Department`).
3. `Webkul\Employee\Models\EmployeeJobPosition` (fila 10, alto; cierra transitivamente filas 25 y 26, `Recruitment\JobPosition`/`JobByPosition`).
4. `Webkul\Employee\Models\EmployeeSkill` (fila 12, alto, Resource global buscable cross-company).
5. `Webkul\Employee\Models\WorkLocation` (fila 14, medio).

Archivos estimados (sin contar tests): 5 modelos + 1 archivo de manifest (`config/company-scope-exceptions.php` si se agregan alias de `Recruitment\Department`/`JobPosition`/`JobByPosition`) + regeneracion de `docs/security/company-scope-pr4-inventory.json`. Sin nuevas migraciones.

Contrato de aislamiento: identico al usado en ola 4A/4B (`HasCompanyScope` + `HasStrictCompanyId` en cada owner; los 3 alias de Recruitment heredan por late static binding, igual que se verifico empiricamente para BankAccount en ola 4C). Antes de cerrar, verificar explicitamente que `DepartmentResource`, `JobPositionResource` y `JobByPositionResource` (las 3 Resources independientes de `recruitments` sobre las mismas tablas) no llamen `withoutGlobalScope()` en ningun punto.

Pruebas: siguiendo el patron de ola 4A/4B, minimo por modelo: lectura misma compania, lectura oculta de otra compania, creacion/actualizacion cross-company rechazada, reasignacion rechazada donde aplique, `CompanyContext::runForCompany/runForAllCompanies/runForBootstrap`. Adicional para los 3 alias de Recruitment: un test directo de herencia por clase (mismo patron que los 3 tests de alias de BankAccount en ola 4C) mas una verificacion de que su Resource propia no enumera filas ocultas.

Conteos esperados del inventario (estimado, a confirmar con el auditor real tras implementar, no asumido de antemano): `scoped` 126 -> 134 (8 filas: 5 owners + 3 alias), gaps reales 45 -> 37.

Riesgos de regresion: `Employee` tiene logica de `boot()` que valida bank account y crea/actualiza un `Partner` asociado; anadir `HasCompanyScope` requiere confirmar que esa creacion de Partner sigue funcionando bajo los mismos `CompanyContext` usados en ola 4A/4B (patron ya resuelto para Candidate/Recruitment en ola anterior, referencia directa disponible). `EmployeeSkill` tiene una Resource global (no scoped por relacion de padre): confirmar que el listado sigue siendo util para un actor multi-compania (via `allowedCompanyIds()`) y no queda vacio por error de precedencia.

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
- **`ActivityType` (recruitments/sales) y `Recruitment\Stage`**: reclasificacion de manifest, no trabajo de codigo.
- **`Security\Invitation`**: reclasificacion de manifest (excepcion ya documentada), no trabajo de codigo.
- **Backfill historico de BankAccount** (deuda declarada desde ola 4B, `A4B-AUD-007`): sigue diferido, no forma parte de A4D.
- **Ampliacion general del runner canonico** (`payments`, `plugin-manager`, `products`, `recruitments` fuera de `phpunit.xml`): decision independiente de infraestructura de tests, no debe mezclarse con ninguna ola de scope.
- **`OrderTemplateProduct::boot()` (default `::first()`) y `CurrencyRate` (API `filter[company_id]` abierto)**: bugs de severidad propia, cada uno merece su propio commit de fix, priorizado por riesgo, no absorbido por el empaquetado de una ola.
- **Delete/restore sin reautorizacion general para filas no compartidas** (deuda declarada desde ola 4B, `A4B-AUD-005`): sigue diferida, transversal a multiples modelos ya scoped, no es parte de A4D.

## Recomendacion final

Adoptar la propuesta principal (`Employee`, `Department`, `EmployeeJobPosition`, `EmployeeSkill`, `WorkLocation`) como ola 4D: cubre el mayor riesgo real (PII de empleados directamente listable sin scope), sigue el mismo patron ya probado en 4A/4B/4C, y no introduce cambios transversales de `CompanyScope`, taxonomia nueva, backfill ni ampliacion de runner. Antes de implementar, reclasificar (solo documentalmente, sin tocar codigo) `ActivityType` (2 alias), `Recruitment\Stage` (pendiente de confirmacion de producto) y `Security\Invitation` en el manifest, ya que no son gaps reales y distorsionan el conteo de "45 pendientes". Registrar como hallazgos de severidad independiente (no como parte de ninguna ola de scope) el bug de `OrderTemplateProduct::boot()` y la fuga activa de `Support\CurrencyRate` via API, dado que esta ultima ya es explotable hoy y no deberia esperar a que le toque su turno de dominio.
