import { Head, router, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import type { FormEvent } from 'react';
import AuthLayout from '@/layouts/auth-layout';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/** Primer ingreso con la contraseña temporal que dio un administrador. */
export default function ChangePassword({ email }: { email: string }) {
  const { data, setData, put, processing, errors, reset } = useForm({
    password: '',
    password_confirmation: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    put('/cuenta/clave', {
      onFinish: () => reset('password', 'password_confirmation'),
    });
  };

  return (
    <AuthLayout
      title="Elija su contraseña"
      subtitle="Ingresó con una contraseña temporal. Reemplácela por una propia, que no use en otros servicios."
    >
      <Head title="Elija su contraseña" />

      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Correo electrónico">
          {(props) => <Input {...props} type="email" value={email} readOnly autoComplete="username" />}
        </Field>

        <Field
          label="Nueva contraseña"
          hint="Mínimo 12 caracteres, con mayúsculas, minúsculas y números."
          error={errors.password}
          required
        >
          {(props) => (
            <Input
              {...props}
              type="password"
              value={data.password}
              onChange={(event) => setData('password', event.target.value)}
              autoComplete="new-password"
              autoFocus
              icon={<KeyRound />}
              invalid={Boolean(errors.password)}
            />
          )}
        </Field>

        <Field label="Repetir contraseña" error={errors.password_confirmation} required>
          {(props) => (
            <Input
              {...props}
              type="password"
              value={data.password_confirmation}
              onChange={(event) => setData('password_confirmation', event.target.value)}
              autoComplete="new-password"
              icon={<KeyRound />}
              invalid={Boolean(errors.password_confirmation)}
            />
          )}
        </Field>

        <Button type="submit" variant="primary" size="lg" loading={processing} className="mt-1 w-full">
          Guardar y entrar
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={() => router.post('/salir')}>
          Salir
        </Button>
      </form>
    </AuthLayout>
  );
}
