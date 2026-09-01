# Criterios de aceptación

Cada criterio indica la prueba que lo verifica. Estado a 3 de agosto de 2026: **82 pruebas, 212 aserciones, todas en verde**.

## Instalación y arranque

| # | Criterio | Verificación | Estado |
|---|---|---|---|
| I1 | El proyecto se instala con `composer install` y `npm install` | Manual — ver `docs/instalacion-local.md` | ✅ |
| I2 | Las migraciones se ejecutan sobre MySQL sin errores | `php artisan migrate:fresh` | ✅ |
| I3 | Los seeders pueblan roles, catálogos y datos demo | `php artisan db:seed` | ✅ |
| I4 | El frontend compila para producción | `npm run build` | ✅ |
| I5 | TypeScript compila en modo estricto sin errores | `npm run types` | ✅ |
| I6 | No hay credenciales en el repositorio | `.env.example` con valores vacíos | ✅ |

## Autenticación

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| A1 | Un usuario activo inicia sesión | `AuthenticationTest::test_un_usuario_activo_puede_iniciar_sesion` | ✅ |
| A2 | Las credenciales inválidas no autentican | `..._las_credenciales_invalidas_no_autentican` | ✅ |
| A3 | Un usuario desactivado no puede entrar | `..._un_usuario_desactivado_no_puede_iniciar_sesion` | ✅ |
| A4 | La sesión se regenera tras autenticar | `..._el_identificador_de_sesion_cambia_tras_autenticar` | ✅ |
| A5 | Cinco fallos bloquean la cuenta | `..._tras_cinco_intentos_fallidos_la_cuenta_queda_bloqueada` | ✅ |
| A6 | El bloqueo no afecta a otros usuarios | `..._el_bloqueo_es_por_correo_e_ip_y_no_afecta_a_otro_usuario` | ✅ |
| A7 | La recuperación no revela si el correo existe | `..._el_formulario_de_recuperacion_no_revela_si_el_correo_existe` | ✅ |
| A8 | Se restablece la contraseña con token válido | `..._se_puede_restablecer_la_contrasena_con_un_token_valido` | ✅ |
| A9 | Un token inválido no restablece nada | `..._un_token_invalido_no_restablece_la_contrasena` | ✅ |
| A10 | Se rechazan contraseñas débiles | `..._se_rechaza_una_contrasena_debil_al_restablecer` | ✅ |
| A11 | Desactivar corta la sesión en la petición siguiente | `..._un_usuario_desactivado_pierde_el_acceso_en_la_siguiente_peticion` | ✅ |
| A12 | La auditoría nunca guarda la contraseña | `..._la_auditoria_nunca_guarda_la_contrasena` | ✅ |

## Aislamiento entre organizaciones

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| T1 | Un cliente sólo ve sus activos | `TenantIsolationTest::test_un_cliente_solo_ve_sus_propios_activos_en_el_listado` | ✅ |
| T2 | Un activo ajeno devuelve 404 | `..._acceder_a_un_activo_de_otra_organizacion_devuelve_404` | ✅ |
| T3 | Un informe ajeno devuelve 404 | `..._acceder_a_un_informe_de_otra_organizacion_devuelve_404` | ✅ |
| T4 | Un set ajeno devuelve 404 | `..._acceder_a_un_set_de_otra_organizacion_devuelve_404` | ✅ |
| T5 | No se descarga un documento ajeno | `..._no_se_puede_descargar_un_documento_de_otra_organizacion` | ✅ |
| T6 | No se ven recepciones ajenas | `..._un_cliente_no_ve_recepciones_de_otra_organizacion` | ✅ |
| T7 | El personal interno sí alcanza cualquier organización | `..._el_personal_interno_si_alcanza_datos_de_cualquier_organizacion` | ✅ |
| T8 | Un cliente no gestiona usuarios ajenos | `..._un_cliente_no_puede_gestionar_usuarios_de_otra_organizacion` | ✅ |
| T9 | El listado de usuarios de un cliente no incluye personal de KPK | `..._el_listado_de_usuarios_de_un_cliente_no_incluye_personal_de_kpk` | ✅ |

## Permisos

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| P1 | Consulta no alcanza administración | `PermissionsTest::test_un_usuario_de_consulta_no_alcanza_la_administracion` | ✅ |
| P2 | Consulta no alcanza taller | `..._un_usuario_de_consulta_no_alcanza_el_area_de_taller` | ✅ |
| P3 | Consulta sí ve activos, informes y sets | `..._un_usuario_de_consulta_si_puede_ver_activos_e_informes` | ✅ |
| P4 | Un técnico no finaliza informes | `..._un_tecnico_no_puede_finalizar_un_informe` | ✅ |
| P5 | El responsable de taller sí finaliza | `..._el_responsable_de_taller_si_puede_finalizar_un_informe` | ✅ |
| P6 | Recepción despacha pero no libera | `..._recepcion_no_puede_liberar_pero_si_despachar` | ✅ |
| P7 | El superadministrador supera toda comprobación | `..._el_superadministrador_supera_cualquier_comprobacion` | ✅ |
| P8 | Un cliente no se otorga un rol interno | `..._un_cliente_no_puede_otorgarse_un_rol_interno_de_kpk` | ✅ |
| P9 | Un cliente no crea usuarios en otra organización | `..._un_cliente_no_puede_crear_usuarios_en_otra_organizacion` | ✅ |
| P10 | Nadie se desactiva a sí mismo | `..._nadie_puede_desactivarse_a_si_mismo` | ✅ |

## Regla de aptitud (la regla central del negocio)

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| E1 | Sin inspecciones → estado desconocido | `AssetStatusTest::test_una_pieza_sin_inspecciones_queda_en_estado_desconocido` | ✅ |
| E2 | Apto vigente → apta | `..._un_dictamen_apto_vigente_deja_la_pieza_apta` | ✅ |
| E3 | Apto dentro de 60 días → próxima a vencer | `..._un_dictamen_apto_dentro_de_la_ventana_preventiva_marca_proximo_a_vencer` | ✅ |
| E4 | Apto vencido → no apta | `..._un_dictamen_apto_ya_vencido_pasa_a_no_apto` | ✅ |
| E5 | No apto manda sobre el vencimiento | `..._un_dictamen_no_apto_manda_sin_importar_el_vencimiento` | ✅ |
| E6 | «No aplica» no altera el estado | `..._las_inspecciones_no_aplica_no_alteran_el_estado` | ✅ |
| E7 | «Sin procesar» tampoco | `..._las_inspecciones_sin_procesar_tampoco_determinan_el_estado` | ✅ |
| E8 | Un FMR en borrador no propaga dictamen | `..._un_fmr_en_borrador_no_propaga_su_dictamen` | ✅ |
| E9 | Manda la inspección más reciente | `..._manda_la_inspeccion_mas_reciente_entre_varios_dictamenes` | ✅ |
| E10 | El recálculo es idempotente | `..._el_recalculo_es_idempotente` | ✅ |

## Flujo de inspección

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| F1 | Finalizar calcula el vencimiento y actualiza la pieza | `InspectionWorkflowTest::test_finalizar_un_informe_calcula_el_vencimiento_y_actualiza_la_pieza` | ✅ |
| F2 | Se respeta un vencimiento fijado a mano | `..._finalizar_respeta_un_vencimiento_fijado_a_mano` | ✅ |
| F3 | No se finaliza un informe sin piezas | `..._no_se_puede_finalizar_un_informe_sin_piezas` | ✅ |
| F4 | No se finaliza dos veces | `..._no_se_puede_finalizar_dos_veces_el_mismo_informe` | ✅ |
| F5 | Finalizar queda auditado | `..._finalizar_queda_registrado_en_la_auditoria` | ✅ |
| F6 | Un informe final es inmutable | `..._un_informe_final_ya_no_es_editable` | ✅ |
| F7 | El detalle lista piezas y documentos | `..._el_detalle_del_informe_lista_sus_piezas_y_documentos` | ✅ |

## Pipeline de taller

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| W1 | Liberar registra hito y usuario | `ReceptionWorkflowTest::test_liberar_registra_el_hito_y_el_usuario` | ✅ |
| W2 | No se despacha sin liberar | `..._no_se_puede_despachar_una_pieza_sin_liberar` | ✅ |
| W3 | Una pieza liberada se despacha | `..._una_pieza_liberada_se_puede_despachar` | ✅ |
| W4 | No se libera dos veces | `..._no_se_puede_liberar_dos_veces` | ✅ |
| W5 | Fuera de plazo se detecta bien | `..._una_recepcion_fuera_de_plazo_se_detecta_correctamente` | ✅ |
| W6 | Los filtros por etapa funcionan | `..._los_filtros_por_etapa_devuelven_lo_esperado` | ✅ |
| W7 | La etapa se deriva de los hitos | `..._la_etapa_del_pipeline_se_calcula_a_partir_de_los_hitos` | ✅ |

## Listados

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| L1 | El listado separa partes de genéricos | `AssetBrowsingTest::test_el_listado_separa_partes_de_genericos` | ✅ |
| L2 | La búsqueda encuentra por número de serie | `..._la_busqueda_libre_encuentra_por_numero_de_serie` | ✅ |
| L3 | Los comodines de LIKE se escapan | `..._los_comodines_de_like_se_escapan_en_la_busqueda` | ✅ |
| L4 | Se filtra por estado | `..._se_puede_filtrar_por_estado` | ✅ |
| L5 | Una columna de orden no permitida se ignora | `..._una_columna_de_orden_no_permitida_se_ignora` | ✅ |
| L6 | La paginación es del lado del servidor | `..._la_paginacion_es_del_lado_del_servidor` | ✅ |
| L7 | Un tamaño de página arbitrario cae al defecto | `..._un_tamano_de_pagina_arbitrario_cae_al_valor_por_defecto` | ✅ |
| L8 | El detalle muestra la bitácora completa | `..._el_detalle_de_una_pieza_muestra_su_bitacora_completa` | ✅ |
| L9 | Consultar una pieza queda auditado | `..._consultar_una_pieza_queda_auditado` | ✅ |

## Documentos

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| D1 | Un usuario autorizado descarga | `DocumentSecurityTest::test_un_usuario_autorizado_descarga_el_documento` | ✅ |
| D2 | Cada descarga queda auditada | `..._cada_descarga_queda_registrada_en_la_auditoria` | ✅ |
| D3 | Sin sesión no hay descarga | `..._un_visitante_sin_sesion_no_alcanza_el_documento` | ✅ |
| D4 | Archivo ausente en disco → 404 | `..._si_el_archivo_no_existe_en_disco_responde_404` | ✅ |
| D5 | El disco privado está fuera de `public/` | `..._el_disco_de_documentos_esta_fuera_del_directorio_publico` | ✅ |
| D6 | Las rutas usan UUID | `..._las_rutas_de_documentos_usan_uuid_y_no_ids_secuenciales` | ✅ |

## Sitio público

| # | Criterio | Prueba | Estado |
|---|---|---|---|
| B1 | Las páginas públicas responden sin sesión | `PublicSiteTest::test_las_paginas_publicas_responden_sin_sesion` | ✅ |
| B2 | Llevan cabeceras de seguridad | `..._las_paginas_publicas_llevan_cabeceras_de_seguridad` | ✅ |
| B3 | Se envía una solicitud válida | `..._se_puede_enviar_una_solicitud_de_acceso_valida` | ✅ |
| B4 | Se exigen los campos obligatorios | `..._la_solicitud_exige_los_campos_obligatorios` | ✅ |
| B5 | El honeypot bloquea bots | `..._el_honeypot_bloquea_los_envios_automatizados` | ✅ |
| B6 | Se rechaza un teléfono con caracteres inválidos | `..._se_rechaza_un_telefono_con_caracteres_invalidos` | ✅ |
| B7 | La solicitud queda auditada | `..._la_solicitud_queda_registrada_en_la_auditoria` | ✅ |
| B8 | El formulario está limitado por IP | `..._el_formulario_de_solicitud_esta_limitado_por_ip` | ✅ |

## Criterios no cubiertos por pruebas automatizadas

| # | Criterio | Cómo verificarlo |
|---|---|---|
| N1 | Contraste WCAG AA en ambos temas | Auditoría manual con herramienta de contraste |
| N2 | Navegación completa por teclado | Recorrido manual de cada flujo |
| N3 | Comportamiento responsive real | Prueba en dispositivos |
| N4 | Pruebas de navegador de extremo a extremo | Pendiente — requiere Playwright o Dusk |
