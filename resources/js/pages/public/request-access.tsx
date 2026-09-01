import { Head, Link, usePage } from '@inertiajs/react';
import { useForm } from '@inertiajs/react';
import { Building2, CheckCircle2, Mail, Phone, User } from 'lucide-react';
import type { FormEvent } from 'react';
import PublicLayout from '@/layouts/public-layout';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input, Textarea } from '@/components/ui/input';
import type { PageProps } from '@/types';

export default function RequestAccess() {
  const { flash } = usePage<PageProps>().props;

  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    email: '',
    phone: '',
    company: '',
    message: '',
    website: '', // honeypot
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/solicitar-acceso', { onSuccess: () => reset() });
  };

  return (
    <PublicLayout>
      <Head title="Solicitar acceso" />

      <section className="mx-auto grid w-full max-w-5xl gap-12 px-5 py-16 lg:grid-cols-[1fr_1.1fr]">
        <div>
          <p className="text-[11px] font-semibold tracking-[0.12em] text-[var(--text-accent)] uppercase">
            Acceso al portal
          </p>
          <h1 className="mt-2 text-[30px] leading-tight font-semibold tracking-tight">
            Solicite una cuenta
          </h1>
          <p className="mt-4 text-[14px] leading-relaxed text-[var(--text-secondary)]">
            El acceso al portal está reservado a clientes con equipamiento en servicio. Un
            administrador revisa cada solicitud y, si corresponde, crea la cuenta asociada a su
            organización.
          </p>

          <ul className="mt-7 flex flex-col gap-3">
            {[
              'La revisión es manual: nadie obtiene acceso automático.',
              'Su cuenta queda asociada a una sola organización.',
              'Recibirá un enlace para definir su propia contraseña.',
            ].map((item) => (
              <li key={item} className="flex items-start gap-2.5 text-[13px]">
                <CheckCircle2
                  className="mt-0.5 size-4 shrink-0 text-[var(--tone-success-fg)]"
                  aria-hidden
                />
                <span className="text-[var(--text-secondary)]">{item}</span>
              </li>
            ))}
          </ul>

          <p className="mt-8 text-[12.5px] text-[var(--text-secondary)]">
            ¿Ya tiene cuenta?{' '}
            <Link href="/ingresar" className="text-[var(--text-accent)] hover:underline">
              Ingresar
            </Link>
          </p>
        </div>

        <div className="rounded-sm border border-[var(--border-subtle)] bg-[var(--surface-raised)] p-6 shadow-[var(--shadow-panel)]">
          {flash?.success ? (
            <div
              role="status"
              className="flex flex-col items-start gap-3 rounded-xs border border-[var(--tone-success-fg)]/25 bg-[var(--tone-success-bg)] p-5"
            >
              <CheckCircle2 className="size-5 text-[var(--tone-success-fg)]" aria-hidden />
              <p className="text-[13.5px] font-medium text-[var(--tone-success-fg)]">
                {flash.success}
              </p>
              <Button asChild variant="secondary" size="sm">
                <Link href="/">Volver al inicio</Link>
              </Button>
            </div>
          ) : (
            <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
              <Field label="Nombre y apellido" error={errors.name} required>
                {(props) => (
                  <Input
                    {...props}
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    autoComplete="name"
                    icon={<User />}
                    invalid={Boolean(errors.name)}
                  />
                )}
              </Field>

              <Field label="Correo electrónico" error={errors.email} required>
                {(props) => (
                  <Input
                    {...props}
                    type="email"
                    value={data.email}
                    onChange={(event) => setData('email', event.target.value)}
                    autoComplete="email"
                    placeholder="nombre@empresa.com"
                    icon={<Mail />}
                    invalid={Boolean(errors.email)}
                  />
                )}
              </Field>

              <Field label="Empresa" error={errors.company} required>
                {(props) => (
                  <Input
                    {...props}
                    value={data.company}
                    onChange={(event) => setData('company', event.target.value)}
                    autoComplete="organization"
                    icon={<Building2 />}
                    invalid={Boolean(errors.company)}
                  />
                )}
              </Field>

              <Field label="Teléfono" hint="Opcional. Nos ayuda a validar la solicitud más rápido." error={errors.phone}>
                {(props) => (
                  <Input
                    {...props}
                    type="tel"
                    value={data.phone}
                    onChange={(event) => setData('phone', event.target.value)}
                    autoComplete="tel"
                    icon={<Phone />}
                    invalid={Boolean(errors.phone)}
                  />
                )}
              </Field>

              <Field label="Comentario" hint="Opcional. Indique su área o el motivo del acceso." error={errors.message}>
                {(props) => (
                  <Textarea
                    {...props}
                    value={data.message}
                    onChange={(event) => setData('message', event.target.value)}
                    maxLength={1000}
                    invalid={Boolean(errors.message)}
                  />
                )}
              </Field>

              {/* Trampa para bots: invisible y fuera del orden de tabulación. */}
              <div aria-hidden className="hidden">
                <label htmlFor="website">No completar</label>
                <input
                  id="website"
                  type="text"
                  tabIndex={-1}
                  autoComplete="off"
                  value={data.website}
                  onChange={(event) => setData('website', event.target.value)}
                />
              </div>

              <Button type="submit" variant="primary" size="lg" loading={processing} className="mt-1">
                Enviar solicitud
              </Button>

              <p className="text-[11.5px] leading-relaxed text-[var(--text-muted)]">
                Sus datos se usan únicamente para evaluar la solicitud de acceso.
              </p>
            </form>
          )}
        </div>
      </section>
    </PublicLayout>
  );
}
