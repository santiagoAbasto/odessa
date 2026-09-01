import { Head, Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import PublicLayout from '@/layouts/public-layout';
import { Button } from '@/components/ui/button';

interface Capability {
  key: string;
  title: string;
  summary: string;
  points: string[];
}

export default function Services({ capabilities }: { capabilities: Capability[] }) {
  return (
    <PublicLayout>
      <Head title="Servicios" />

      <section className="border-b border-[var(--border-subtle)]">
        <div className="mx-auto w-full max-w-6xl px-5 py-16">
          <p className="text-[11px] font-semibold tracking-[0.12em] text-[var(--text-accent)] uppercase">
            Servicios
          </p>
          <h1 className="mt-2 max-w-3xl text-[34px] leading-tight font-semibold tracking-tight">
            Intervenciones sobre equipamiento de presión, con dictamen y vigencia
          </h1>
          <p className="mt-4 max-w-2xl text-[14.5px] leading-relaxed text-[var(--text-secondary)]">
            Cada servicio deja registro en el historial de la pieza. Los que emiten dictamen renuevan
            su vigencia; los operativos quedan documentados sin alterar la certificación en curso.
          </p>
        </div>
      </section>

      <section className="bg-[var(--surface-sunken)]">
        <div className="mx-auto w-full max-w-6xl px-5 py-16">
          <div className="flex flex-col gap-px overflow-hidden rounded-sm border border-[var(--border-subtle)] bg-[var(--border-subtle)]">
            {capabilities.map((capability, index) => (
              <article
                key={capability.key}
                className="grid gap-6 bg-[var(--surface-raised)] p-7 sm:grid-cols-[auto_1fr_1fr] sm:items-start"
              >
                <span className="font-mono text-[12px] font-semibold text-[var(--text-accent)]">
                  {String(index + 1).padStart(2, '0')}
                </span>

                <div>
                  <h2 className="text-[16px] font-semibold tracking-tight">{capability.title}</h2>
                  <p className="mt-2 text-[13px] leading-relaxed text-[var(--text-secondary)]">
                    {capability.summary}
                  </p>
                </div>

                <ul className="flex flex-col gap-2">
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

          <div className="mt-10 flex flex-wrap items-center gap-3">
            <Button asChild variant="primary" size="lg">
              <Link href="/solicitar-acceso">Solicitar acceso al portal</Link>
            </Button>
            <Button asChild variant="secondary" size="lg">
              <Link href="/ingresar">Ya tengo cuenta</Link>
            </Button>
          </div>
        </div>
      </section>
    </PublicLayout>
  );
}
