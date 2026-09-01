import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Mail } from 'lucide-react';
import type { FormEvent } from 'react';
import AuthLayout from '@/layouts/auth-layout';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

export default function ForgotPassword({ status }: { status?: string }) {
  const { data, setData, post, processing, errors } = useForm({ email: '' });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/recuperar-clave');
  };

  return (
    <AuthLayout
      title="Recuperar contraseña"
      subtitle="Le enviaremos un enlace para definir una nueva contraseña."
    >
      <Head title="Recuperar contraseña" />

      {status && (
        <div
          role="status"
          className="mb-5 rounded-xs border border-[var(--tone-info-fg)]/25 bg-[var(--tone-info-bg)] px-3 py-2.5 text-[12.5px] text-[var(--tone-info-fg)]"
        >
          {status}
        </div>
      )}

      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field
          label="Correo electrónico"
          hint="Debe ser el correo con el que accede a la plataforma."
          error={errors.email}
          required
        >
          {(props) => (
            <Input
              {...props}
              type="email"
              value={data.email}
              onChange={(event) => setData('email', event.target.value)}
              autoComplete="username"
              autoFocus
              placeholder="nombre@empresa.com"
              icon={<Mail />}
              invalid={Boolean(errors.email)}
            />
          )}
        </Field>

        <Button type="submit" variant="primary" size="lg" loading={processing} className="w-full">
          Enviar enlace
        </Button>
      </form>

      <Link
        href="/ingresar"
        className="mt-6 inline-flex items-center gap-1.5 text-[12.5px] text-[var(--text-secondary)] hover:text-[var(--text-accent)]"
      >
        <ArrowLeft className="size-3.5" aria-hidden />
        Volver al inicio de sesión
      </Link>
    </AuthLayout>
  );
}
