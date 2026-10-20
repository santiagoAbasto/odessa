import { Head, useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import type { FormEvent } from 'react';
import AuthLayout from '@/layouts/auth-layout';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/**
 * Elegir contraseña desde un enlace por correo.
 *
 * Sirve al "olvidé mi contraseña" y a la bienvenida de un usuario nuevo. Cambia
 * el texto y la dirección a la que se manda: cada enlace se valida contra su
 * propio vencimiento, y eso lo decide el servidor según la ruta.
 */
export default function ResetPassword({
  token,
  email,
  welcome = false,
}: {
  token: string;
  email: string;
  welcome?: boolean;
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    token,
    email,
    password: '',
    password_confirmation: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    post(welcome ? '/bienvenida' : '/restablecer-clave', {
      onFinish: () => reset('password', 'password_confirmation'),
    });
  };

  return (
    <AuthLayout
      title={welcome ? 'Bienvenido' : 'Nueva contraseña'}
      subtitle={
        welcome
          ? 'Elija la contraseña con la que va a entrar al portal. Que no sea una que use en otros servicios.'
          : 'Elija una contraseña que no use en otros servicios.'
      }
    >
      <Head title={welcome ? 'Bienvenido' : 'Nueva contraseña'} />

      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        <Field label="Correo electrónico" error={errors.email}>
          {(props) => (
            <Input {...props} type="email" value={data.email} readOnly autoComplete="username" />
          )}
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
          {welcome ? 'Activar mi cuenta' : 'Guardar contraseña'}
        </Button>
      </form>
    </AuthLayout>
  );
}
