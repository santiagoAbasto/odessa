<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Sitio público.
 *
 * El sistema auditado no tiene presencia pública: la raíz redirige al login.
 * Aquí se agrega una portada abierta que explica el servicio, el proceso de
 * inspección y cómo solicitar acceso, sin exponer ningún dato de clientes.
 */
class PublicSiteController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('public/home', [
            'capabilities' => $this->capabilities(),
            'process' => $this->process(),
        ]);
    }

    public function services(): Response
    {
        return Inertia::render('public/services', [
            'capabilities' => $this->capabilities(),
        ]);
    }

    /**
     * Bloques de servicio. Están derivados de la nomenclatura real de servicios
     * observada en los informes (certificación, operacional, mantenimiento
     * mayor con certificado de conformidad), sin citar datos de ningún cliente.
     */
    private function capabilities(): array
    {
        return [
            [
                'key' => 'certification',
                'title' => 'Certificación de equipos de presión',
                'summary' => 'Ensayo hidrostático, verificación dimensional y emisión del certificado con vigencia trazable.',
                'points' => [
                    'Prueba de presión de trabajo y de test',
                    'Verificación de conexiones y bridas',
                    'Certificado con fecha de vencimiento',
                ],
            ],
            [
                'key' => 'operational',
                'title' => 'Servicio operacional',
                'summary' => 'Intervención de campo y taller sobre equipos en servicio, con registro en la bitácora del activo.',
                'points' => [
                    'Inspección visual y funcional',
                    'Reemplazo de sellos y elementos de desgaste',
                    'Registro fotográfico del hallazgo',
                ],
            ],
            [
                'key' => 'major',
                'title' => 'Mantenimiento mayor',
                'summary' => 'Desarme completo, recuperación de componentes y reensayo con certificado de conformidad.',
                'points' => [
                    'Desarme y limpieza total',
                    'Medición de tolerancias contra especificación',
                    'Reensayo y certificado de conformidad',
                ],
            ],
            [
                'key' => 'traceability',
                'title' => 'Trazabilidad por pieza',
                'summary' => 'Cada pieza mantiene su historial completo de intervenciones, documentos y vencimientos.',
                'points' => [
                    'Historial por número de serie',
                    'Documentos descargables por el cliente',
                    'Alertas antes del vencimiento',
                ],
            ],
        ];
    }

    private function process(): array
    {
        return [
            ['step' => 1, 'title' => 'Recepción', 'description' => 'La pieza ingresa al taller con fecha comprometida de entrega y prioridad asignada.'],
            ['step' => 2, 'title' => 'Inspección', 'description' => 'El técnico ejecuta el servicio correspondiente y documenta cada hallazgo.'],
            ['step' => 3, 'title' => 'Dictamen', 'description' => 'Se emite el informe con resultado apto o no apto y su vigencia.'],
            ['step' => 4, 'title' => 'Liberación', 'description' => 'El responsable de taller libera la pieza una vez validado el informe.'],
            ['step' => 5, 'title' => 'Despacho', 'description' => 'La pieza vuelve al cliente y el portal refleja su nuevo estado al instante.'],
        ];
    }
}
