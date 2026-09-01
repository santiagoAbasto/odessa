import * as DropdownPrimitive from '@radix-ui/react-dropdown-menu';
import { cn } from '@/lib/cn';

export const DropdownMenu = DropdownPrimitive.Root;
export const DropdownMenuTrigger = DropdownPrimitive.Trigger;
export const DropdownMenuSeparator = () => (
  <DropdownPrimitive.Separator className="my-1 h-px bg-[var(--border-subtle)]" />
);

export function DropdownMenuContent({
  children,
  align = 'end',
  className,
}: {
  children: React.ReactNode;
  align?: 'start' | 'center' | 'end';
  className?: string;
}) {
  return (
    <DropdownPrimitive.Portal>
      <DropdownPrimitive.Content
        align={align}
        sideOffset={5}
        className={cn(
          'z-50 min-w-48 rounded-sm border border-[var(--border-default)] bg-[var(--surface-raised)] p-1',
          'shadow-[var(--shadow-overlay)]',
          'duration-100 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95',
          className,
        )}
      >
        {children}
      </DropdownPrimitive.Content>
    </DropdownPrimitive.Portal>
  );
}

export function DropdownMenuItem({
  children,
  onSelect,
  destructive = false,
  disabled = false,
  className,
}: {
  children: React.ReactNode;
  onSelect?: () => void;
  destructive?: boolean;
  disabled?: boolean;
  className?: string;
}) {
  return (
    <DropdownPrimitive.Item
      disabled={disabled}
      onSelect={onSelect}
      className={cn(
        'flex cursor-pointer items-center gap-2 rounded-xs px-2 py-1.5 text-[12.5px] outline-none',
        'data-[highlighted]:bg-[var(--surface-hover)]',
        'data-[disabled]:pointer-events-none data-[disabled]:opacity-45',
        '[&_svg]:size-3.5 [&_svg]:shrink-0 [&_svg]:text-[var(--text-muted)]',
        destructive && 'text-[var(--color-risk-600)] [&_svg]:text-[var(--color-risk-600)]',
        className,
      )}
    >
      {children}
    </DropdownPrimitive.Item>
  );
}

export function DropdownMenuLabel({ children }: { children: React.ReactNode }) {
  return (
    <DropdownPrimitive.Label className="px-2 py-1.5 text-[10px] font-semibold tracking-[0.1em] text-[var(--text-muted)] uppercase">
      {children}
    </DropdownPrimitive.Label>
  );
}

export function DropdownMenuCheckboxItem({
  children,
  checked,
  onCheckedChange,
}: {
  children: React.ReactNode;
  checked: boolean;
  onCheckedChange: (value: boolean) => void;
}) {
  return (
    <DropdownPrimitive.CheckboxItem
      checked={checked}
      onCheckedChange={onCheckedChange}
      onSelect={(event) => event.preventDefault()}
      className={cn(
        'flex cursor-pointer items-center gap-2 rounded-xs py-1.5 pr-2 pl-7 text-[12.5px] outline-none',
        'relative data-[highlighted]:bg-[var(--surface-hover)]',
      )}
    >
      <DropdownPrimitive.ItemIndicator className="absolute left-2">
        <svg viewBox="0 0 12 12" className="size-3" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="m2 6 3 3 5-6" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </DropdownPrimitive.ItemIndicator>
      {children}
    </DropdownPrimitive.CheckboxItem>
  );
}
