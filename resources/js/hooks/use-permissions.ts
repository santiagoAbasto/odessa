import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/**
 * Permisos del usuario para decidir qué se muestra.
 *
 * Es sólo presentación: ocultar un botón no autoriza nada. El backend vuelve
 * a comprobar cada acción con su Policy.
 */
export function usePermissions() {
  const { auth } = usePage<PageProps>().props;
  const permissions = auth?.permissions ?? [];

  return {
    permissions,
    can: (permission: string) => permissions.includes(permission),
    canAny: (...list: string[]) => list.some((p) => permissions.includes(p)),
    user: auth?.user ?? null,
  };
}
