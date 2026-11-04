import { Loader2, Search, X } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Input } from './input';

export interface SearchOption {
  id: string;
  label: string;
  code?: string | null;
}

interface SearchSelectProps {
  /** Endpoint que responde `{ options: SearchOption[] }` para `?q=texto`. */
  url: string;
  /** Id elegido; vacío si no hay ninguno. Si el padre lo vacía, el campo se limpia. */
  value: string;
  onChange: (id: string, option: SearchOption | null) => void;
  placeholder?: string;
  /** Qué mostrar cuando la búsqueda no encuentra nada. */
  emptyMessage?: ReactNode;
  invalid?: boolean;
  disabled?: boolean;
  id?: string;
  'aria-describedby'?: string;
}

/** Espera tras la última tecla antes de consultar: no se pide en cada letra. */
const DEBOUNCE_MS = 250;

/**
 * Selector con búsqueda predictiva.
 *
 * Reemplaza al desplegable cuando la lista puede tener miles de elementos: un
 * <select> obliga a cargarlos todos (y se corta en un tope), y nadie recorre
 * miles de renglones. Acá se escribe, se consulta al servidor y se muestra una
 * tanda corta que se va afinando; con el campo vacío ofrece los primeros.
 *
 * Es un combobox de teclado: flechas para moverse, Enter para elegir, Esc para
 * cerrar. Al elegir muestra la etiqueta elegida y una «×» para soltarla.
 */
export function SearchSelect({
  url,
  value,
  onChange,
  placeholder = 'Buscar…',
  emptyMessage = 'Nada coincide con la búsqueda.',
  invalid,
  disabled,
  id,
  'aria-describedby': describedBy,
}: SearchSelectProps) {
  const listId = useId();
  const [text, setText] = useState('');
  const [selected, setSelected] = useState<SearchOption | null>(null);
  const [open, setOpen] = useState(false);
  const [options, setOptions] = useState<SearchOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [active, setActive] = useState(0);
  const firstRun = useRef(true);

  // El padre vació el valor (por ejemplo, después de guardar): se limpia el campo.
  useEffect(() => {
    if (!value) {
      setSelected(null);
      setText('');
    }
  }, [value]);

  // Consulta con espera. Se cancela la anterior para que una respuesta lenta
  // no pise a una más nueva.
  useEffect(() => {
    if (!open) return;

    const controller = new AbortController();
    const espera = firstRun.current ? 0 : DEBOUNCE_MS;
    firstRun.current = false;

    const timer = setTimeout(() => {
      setLoading(true);
      fetch(`${url}${url.includes('?') ? '&' : '?'}q=${encodeURIComponent(text.trim())}`, {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      })
        .then((response) => (response.ok ? response.json() : { options: [] }))
        .then((payload) => {
          setOptions(payload.options ?? []);
          setActive(0);
          setLoading(false);
        })
        .catch((error) => {
          if (error?.name !== 'AbortError') setLoading(false);
        });
    }, espera);

    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [open, text, url]);

  const choose = (option: SearchOption) => {
    setSelected(option);
    setText('');
    setOpen(false);
    onChange(option.id, option);
  };

  const clear = () => {
    setSelected(null);
    setText('');
    onChange('', null);
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (!open) setOpen(true);
      else setActive((index) => Math.min(index + 1, options.length - 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((index) => Math.max(index - 1, 0));
    } else if (event.key === 'Enter' && open && options[active]) {
      // Sin esto Enter enviaría el formulario que contiene al campo.
      event.preventDefault();
      choose(options[active]);
    } else if (event.key === 'Escape' && open) {
      setOpen(false);
    }
  };

  return (
    <div className="relative">
      <div className="relative">
        <Search
          className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-[var(--text-muted)]"
          aria-hidden
        />
        <Input
          id={id}
          role="combobox"
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={open && options[active] ? `${listId}-${active}` : undefined}
          aria-describedby={describedBy}
          autoComplete="off"
          disabled={disabled}
          invalid={invalid}
          // Elegido se muestra la etiqueta; al escribir se suelta y se busca de nuevo.
          value={selected ? selected.label : text}
          placeholder={placeholder}
          className={cn('pl-8', selected && 'pr-8 font-medium')}
          onFocus={() => setOpen(true)}
          onBlur={() => setOpen(false)}
          onKeyDown={onKeyDown}
          onChange={(event) => {
            if (selected) clear();
            setText(event.target.value);
            setOpen(true);
          }}
        />
        {selected && (
          <button
            type="button"
            onClick={clear}
            aria-label="Quitar la selección"
            className="absolute top-1/2 right-1.5 grid size-6 -translate-y-1/2 place-items-center rounded-xs text-[var(--text-muted)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)]"
          >
            <X className="size-3.5" aria-hidden />
          </button>
        )}
      </div>

      {open && (
        <ul
          id={listId}
          role="listbox"
          // Al apretar un renglón el campo no debe perder el foco: si no, la
          // lista se cierra antes de registrar el clic.
          onMouseDown={(event) => event.preventDefault()}
          className="absolute z-50 mt-1 max-h-60 w-full overflow-y-auto rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] py-1 shadow-lg"
        >
          {loading && options.length === 0 ? (
            <li className="flex items-center gap-2 px-3 py-2 text-[12px] text-[var(--text-secondary)]">
              <Loader2 className="size-3.5 animate-spin" aria-hidden />
              Buscando…
            </li>
          ) : options.length === 0 ? (
            <li className="px-3 py-2 text-[12px] text-[var(--text-secondary)]">{emptyMessage}</li>
          ) : (
            options.map((option, index) => (
              <li
                key={option.id}
                id={`${listId}-${index}`}
                role="option"
                aria-selected={index === active}
                onMouseEnter={() => setActive(index)}
                onClick={() => choose(option)}
                className={cn(
                  'cursor-pointer px-3 py-1.5',
                  index === active && 'bg-[var(--surface-selected)]',
                )}
              >
                <span className="block truncate text-[12.5px]">
                  <Highlight text={option.label} term={text} />
                </span>
                {option.code && (
                  <span className="block truncate font-mono text-[10.5px] text-[var(--text-muted)]">
                    {option.code}
                  </span>
                )}
              </li>
            ))
          )}
        </ul>
      )}
    </div>
  );
}

/** Marca lo que coincide con lo escrito, para que se vea por qué apareció. */
function Highlight({ text, term }: { text: string; term: string }) {
  const buscado = term.trim();
  if (!buscado) return <>{text}</>;

  const inicio = text.toLowerCase().indexOf(buscado.toLowerCase());
  if (inicio === -1) return <>{text}</>;

  return (
    <>
      {text.slice(0, inicio)}
      <mark className="rounded-xs bg-[var(--surface-selected)] px-px text-[var(--text-accent)]">
        {text.slice(inicio, inicio + buscado.length)}
      </mark>
      {text.slice(inicio + buscado.length)}
    </>
  );
}
