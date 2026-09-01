import { Head, Link, useForm } from '@inertiajs/react';
import { KeyRound, Mail } from 'lucide-react';
import type { FormEvent } from 'react';
import AuthLayout from '@/layouts/auth-layout';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

interface LoginProps {
  canResetPassword: boolean;
  status?: string;
}

export default function Login({ canResetPassword, status }: LoginProps) {
  const { data, setData, post, processing, errors, reset } = useForm({
    email: '',
    password: '',
    remember: false as boolean,
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post('/ingresar', {
      // La contraseña nunca queda en memoria tras el intento.
      onFinish: () => reset('password'),
    });
  };

  return (
    <AuthLayout title="Ingresar" subtitle="Acceda con las credenciales que le asignó su administrador.">
      <Head title="Ingresar" />

      {status && (
        <div
          role="status"
          className="mb-5 rounded-xs border border-[var(--tone-success-fg)]/25 bg-[var(--tone-success-bg)] px-3 py-2.5 text-[12.5px] text-[var(--tone-success-fg)]"
        >
          {status}
        </div>
      )}

      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Correo electrónico" error={errors.email} required>
          {(props) => (
            <Input
              {...props}
              type="email"
              name="email"
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

        <Field label="Contraseña" error={errors.password} required>
          {(props) => (
            <Input
              {...props}
              type="password"
              name="password"
              value={data.password}
              onChange={(event) => setData('password', event.target.value)}
              autoComplete="current-password"
              icon={<KeyRound />}
              invalid={Boolean(errors.password)}
            />
          )}
        </Field>

        <div className="flex items-center justify-between gap-3">
          <label className="flex cursor-pointer items-center gap-2 text-[12.5px] text-[var(--text-secondary)]">
            <input
              type="checkbox"
              checked={data.remember}
              onChange={(event) => setData('remember', event.target.checked)}
              className="size-3.5 rounded-xs accent-[var(--accent)]"
            />
            Mantener sesión iniciada
          </label>

          {canResetPassword && (
            <Link
              href="/recuperar-clave"
              className="text-[12.5px] text-[var(--text-accent)] hover:underline"
            >
              ¿Olvidó su contraseña?
            </Link>
          )}
        </div>

        {/* `loading` deshabilita el botón: el doble envío no llega al servidor. */}
        <Button type="submit" variant="primary" size="lg" loading={processing} className="mt-1 w-full">
          Ingresar
        </Button>
      </form>

      <p className="mt-6 text-center text-[12.5px] text-[var(--text-secondary)]">
        ¿No tiene una cuenta?{' '}
        <Link href="/solicitar-acceso" className="text-[var(--text-accent)] hover:underline">
          Solicite acceso
        </Link>
      </p>
    </AuthLayout>
  );
}
