import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, ShieldCheck } from 'lucide-react';
import PublicLayout from '@/layouts/public-layout';
import { Button } from '@/components/ui/button';

interface Capability {
  key: string;
  title: string;
  summary: string;
  points: string[];
}

interface ProcessStep {
  step: number;
  title: string;
  description: string;
}

export default function Home({
  capabilities,
  process,
}: {
  capabilities: Capability[];
  process: ProcessStep[];
}) {
  return (
    <PublicLayout>
      <Head title="Inspección y certificación de equipamiento de presión" />

      {/* ================= Portada ================= */}
      <section className="relative overflow-hidden border-b border-[var(--border-subtle)]">
        <div className="blueprint-grid pointer-events-none absolute inset-0 opacity-60" aria-hidden />

        <div className="relative mx-auto grid w-full max-w-6xl gap-12 px-5 py-20 lg:grid-cols-[1.1fr_1fr] lg:items-center lg:py-28">
          <div>
            <p className="inline-flex items-center gap-2 rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] px-2.5 py-1 text-[11px] font-medium tracking-[0.06em] text-[var(--text-secondary)] uppercase">
              <ShieldCheck className="size-3.5 text-[var(--text-accent)]" aria-hidden />
              Inspección certificada
            </p>

            <h1 className="mt-5 text-[38px] leading-[1.08] font-semibold tracking-tight sm:text-[46px]">
              Cada pieza, con su historial{' '}
              <span className="text-[var(--text-accent)]">completo y verificable</span>.
            </h1>

            <p className="mt-5 max-w-xl text-[15px] leading-relaxed text-[var(--text-secondary)]">
              Inspeccionamos, certificamos y damos trazabilidad al equipamiento de presión de la
              industria energética. Nuestros clientes consultan el estado de su equipamiento en
              cualquier momento, sin llamadas ni planillas.
            </p>

            <div className="mt-8 flex flex-wrap gap-3">
              <Button asChild variant="primary" size="lg">
                <Link href="/solicitar-acceso">
                  Solicitar acceso
                  <ArrowRight aria-hidden />
                </Link>
              </Button>
              <Button asChild variant="secondary" size="lg">
                <Link href="/servicios">Ver servicios</Link>
              </Button>
            </div>
          </div>

          <FlangeDiagram />
        </div>
      </section>

      {/* ================= Servicios ================= */}
      <section className="border-b border-[var(--border-subtle)] bg-[var(--surface-sunken)]">
        <div className="mx-auto w-full max-w-6xl px-5 py-20">
          <p className="text-[11px] font-semibold tracking-[0.12em] text-[var(--text-accent)] uppercase">
            Servicios
          </p>
          <h2 className="mt-2 max-w-2xl text-[28px] leading-tight font-semibold tracking-tight">
            Del ensayo al certificado, con registro de cada intervención
          </h2>

          <div className="mt-10 grid gap-px overflow-hidden rounded-sm border border-[var(--border-subtle)] bg-[var(--border-subtle)] sm:grid-cols-2">
            {capabilities.map((capability) => (
              <article key={capability.key} className="bg-[var(--surface-raised)] p-6">
                <h3 className="text-[15px] font-semibold tracking-tight">{capability.title}</h3>
                <p className="mt-2 text-[13px] leading-relaxed text-[var(--text-secondary)]">
                  {capability.summary}
                </p>
                <ul className="mt-4 flex flex-col gap-2">
                  {capability.points.map((point) => (
                    <li key={point} className="flex items-start gap-2 text-[12.5px]">
                      <CheckCircle2
                        className="mt-0.5 size-3.5 shrink-0 text-[var(--tone-success-fg)]"
                        aria-hidden
                      />
                      <span className="text-[var(--text-secondary)]">{point}</span>
                    </li>
                  ))}
                </ul>
              </article>
            ))}
          </div>
        </div>
      </section>

      {/* ================= Proceso ================= */}
      <section className="border-b border-[var(--border-subtle)]">
        <div className="mx-auto w-full max-w-6xl px-5 py-20">
          <p className="text-[11px] font-semibold tracking-[0.12em] text-[var(--text-accent)] uppercase">
            Proceso
          </p>
          <h2 className="mt-2 max-w-2xl text-[28px] leading-tight font-semibold tracking-tight">
            Cinco etapas trazadas de punta a punta
          </h2>

          <ol className="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-5">
            {process.map((step) => (
              <li key={step.step} className="relative border-t-2 border-[var(--accent)] pt-4">
                <span className="font-mono text-[11px] font-semibold text-[var(--text-accent)]">
                  {String(step.step).padStart(2, '0')}
                </span>
                <h3 className="mt-1.5 text-[14px] font-semibold tracking-tight">{step.title}</h3>
                <p className="mt-1.5 text-[12.5px] leading-relaxed text-[var(--text-secondary)]">
                  {step.description}
                </p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      {/* ================= Cierre ================= */}
      <section className="bg-[var(--color-steel-950)] text-white">
        <div className="mx-auto flex w-full max-w-6xl flex-col items-start gap-6 px-5 py-16 sm:flex-row sm:items-center sm:justify-between">
          <div className="max-w-xl">
            <h2 className="text-[24px] leading-tight font-semibold tracking-tight">
              ¿Ya trabaja con nosotros?
            </h2>
            <p className="mt-2 text-[13.5px] leading-relaxed text-white/60">
              Solicite acceso al portal y consulte el estado de su equipamiento cuando lo necesite.
            </p>
          </div>
          <div className="flex shrink-0 gap-3">
            <Button asChild variant="primary" size="lg">
              <Link href="/solicitar-acceso">Solicitar acceso</Link>
            </Button>
            <Button
              asChild
              size="lg"
              className="border border-white/25 bg-transparent text-white hover:bg-white/10"
            >
              <Link href="/ingresar">Ingresar</Link>
            </Button>
          </div>
        </div>
      </section>
    </PublicLayout>
  );
}

/**
 * Diagrama técnico de una brida en corte.
 *
 * Es una ilustración vectorial construida a mano, no un adorno genérico:
 * anillo, perforaciones, cotas y línea de eje, en el lenguaje de un plano.
 */
function FlangeDiagram() {
  return (
    <div className="relative hidden justify-self-center lg:block">
      <svg
        viewBox="0 0 340 340"
        className="h-[340px] w-[340px] text-[var(--text-accent)]"
        role="img"
        aria-label="Diagrama técnico de una brida de presión con sus perforaciones y cotas"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
      >
        {/* Ejes de referencia */}
        <g stroke="currentColor" strokeWidth="1" opacity="0.28" strokeDasharray="10 4 2 4">
          <path d="M170 12v316" />
          <path d="M12 170h316" />
        </g>

        {/* Cuerpo de la brida */}
        <circle cx="170" cy="170" r="132" stroke="currentColor" strokeWidth="2" opacity="0.9" />
        <circle cx="170" cy="170" r="122" stroke="currentColor" strokeWidth="1" opacity="0.35" />

        {/* Círculo de perforaciones */}
        <circle
          cx="170"
          cy="170"
          r="98"
          stroke="currentColor"
          strokeWidth="1"
          opacity="0.35"
          strokeDasharray="6 5"
        />

        {/* Ocho perforaciones a 45° */}
        {Array.from({ length: 8 }).map((_, index) => {
          const angle = (index * Math.PI) / 4 - Math.PI / 2;
          const cx = 170 + 98 * Math.cos(angle);
          const cy = 170 + 98 * Math.sin(angle);
          return (
            <g key={index}>
              <circle cx={cx} cy={cy} r="13" stroke="currentColor" strokeWidth="2" opacity="0.85" />
              <circle cx={cx} cy={cy} r="3" fill="currentColor" opacity="0.5" />
            </g>
          );
        })}

        {/* Alojamiento del anillo de sello */}
        <circle cx="170" cy="170" r="62" stroke="currentColor" strokeWidth="2" opacity="0.9" />
        <circle cx="170" cy="170" r="52" stroke="currentColor" strokeWidth="1" opacity="0.4" />

        {/* Bore central con achurado */}
        <circle cx="170" cy="170" r="38" stroke="currentColor" strokeWidth="2" opacity="0.9" />
        <g stroke="currentColor" strokeWidth="0.75" opacity="0.22">
          {Array.from({ length: 9 }).map((_, index) => (
            <path key={index} d={`M${138 + index * 8} 137 L${138 + index * 8 + 26} 203`} />
          ))}
        </g>

        {/* Cota de diámetro exterior */}
        <g stroke="currentColor" strokeWidth="1" opacity="0.5">
          <path d="M38 302h264" />
          <path d="M38 296v12M302 296v12" />
        </g>
        <text
          x="170"
          y="322"
          textAnchor="middle"
          className="fill-current font-mono text-[10px]"
          opacity="0.6"
        >
          Ø EXTERIOR
        </text>

        {/* Marca de referencia superior */}
        <g stroke="currentColor" strokeWidth="1" opacity="0.5">
          <path d="M170 38v-22M164 22l6-6 6 6" />
        </g>
      </svg>
    </div>
  );
}
