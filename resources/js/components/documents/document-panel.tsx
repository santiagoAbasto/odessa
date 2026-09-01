import { router, useForm } from '@inertiajs/react';
import { Download, FileText, Paperclip, Trash2, Upload } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmButton } from '@/components/ui/confirm-button';
import { Dialog, DialogClose, DialogContent, DialogTrigger } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Field } from '@/components/ui/field';
import { Input, Select } from '@/components/ui/input';
import { Panel, PanelHeader } from '@/components/ui/panel';
import { formatDate } from '@/lib/format';
import type { SelectOption } from '@/types';

export interface DocumentPayload {
  id: string;
  name: string;
  category: string;
  categoryLabel: string;
  size: string;
  documentDate: string | null;
  notes: string | null;
  uploadedBy: string | null;
  uploadedAt: string | null;
}

interface DocumentPanelProps {
  title: string;
  description?: string;
  documents: DocumentPayload[];
  categories: SelectOption[];
  /** Ruta de carga; si falta, el panel queda en sólo lectura. */
  uploadUrl?: string;
  canUpload: boolean;
  canDelete: boolean;
  emptyHint?: string;
}

/**
 * Panel de documentos adjuntos.
 *
 * Se usa igual para un informe y para una pieza: sólo cambia la ruta de carga.
 * Los clientes lo ven en sólo lectura — subir archivos es del taller.
 */
export function DocumentPanel({
  title,
  description,
  documents,
  categories,
  uploadUrl,
  canUpload,
  canDelete,
  emptyHint,
}: DocumentPanelProps) {
  return (
    <Panel>
      <PanelHeader
        title={title}
        description={description}
        actions={
          canUpload && uploadUrl ? (
            <UploadDialog uploadUrl={uploadUrl} categories={categories} />
          ) : undefined
        }
      />

      {documents.length === 0 ? (
        <EmptyState
          icon={Paperclip}
          title="Sin documentos"
          description={emptyHint ?? 'Todavía no hay archivos adjuntos.'}
        />
      ) : (
        <ul className="divide-y divide-[var(--border-subtle)]">
          {documents.map((document) => (
            <li key={document.id} className="flex items-center gap-3 px-4 py-2.5">
              <FileText className="size-4 shrink-0 text-[var(--text-muted)]" aria-hidden />

              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <a
                    href={`/documentos/${document.id}/descargar`}
                    className="truncate text-[12.5px] font-medium text-[var(--text-accent)] hover:underline"
                  >
                    {document.name}
                  </a>
                  <Badge tone="neutral" withIcon={false}>
                    {document.categoryLabel}
                  </Badge>
                </div>

                <p className="mt-0.5 text-[11px] text-[var(--text-muted)]">
                  {document.size}
                  {document.documentDate && ` · ${formatDate(document.documentDate)}`}
                  {document.uploadedBy && ` · ${document.uploadedBy}`}
                </p>

                {document.notes && (
                  <p className="mt-1 text-[11.5px] text-[var(--text-secondary)]">{document.notes}</p>
                )}
              </div>

              <div className="flex shrink-0 items-center gap-1">
                <Button asChild variant="ghost" size="icon" aria-label={`Descargar ${document.name}`}>
                  <a href={`/documentos/${document.id}/descargar`}>
                    <Download aria-hidden />
                  </a>
                </Button>

                {canDelete && (
                  <ConfirmButton
                    variant="ghost"
                    size="icon"
                    destructive
                    title="Eliminar documento"
                    description={`Se eliminará «${document.name}». El archivo se borra del almacenamiento y no se puede recuperar.`}
                    confirmLabel="Eliminar"
                    onConfirm={() =>
                      router.delete(`/documentos/${document.id}`, { preserveScroll: true })
                    }
                  >
                    <Trash2 aria-hidden />
                    <span className="sr-only">Eliminar {document.name}</span>
                  </ConfirmButton>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

function UploadDialog({ uploadUrl, categories }: { uploadUrl: string; categories: SelectOption[] }) {
  const fileRef = useRef<HTMLInputElement>(null);

  const { data, setData, post, processing, errors, reset, progress } = useForm<{
    file: File | null;
    category: string;
    document_date: string;
    notes: string;
  }>({
    file: null,
    category: categories[0]?.value ?? 'other',
    document_date: '',
    notes: '',
  });

  const submit = (event: FormEvent) => {
    event.preventDefault();

    post(uploadUrl, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        reset();
        if (fileRef.current) fileRef.current.value = '';
      },
    });
  };

  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="secondary" size="sm">
          <Upload aria-hidden />
          Adjuntar
        </Button>
      </DialogTrigger>

      <DialogContent
        title="Adjuntar documento"
        description="PDF o imagen, hasta 20 MB."
        footer={
          <>
            <DialogClose asChild>
              <Button variant="secondary" size="sm">
                Cancelar
              </Button>
            </DialogClose>
            <Button type="submit" form="form-adjuntar" variant="primary" size="sm" loading={processing}>
              Subir
            </Button>
          </>
        }
      >
        <form id="form-adjuntar" onSubmit={submit} className="flex flex-col gap-4" noValidate>
          <Field label="Archivo" error={errors.file} required>
            {(props) => (
              <input
                {...props}
                ref={fileRef}
                type="file"
                accept=".pdf,.jpg,.jpeg,.png,.webp"
                onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                className="w-full rounded-xs border border-[var(--border-default)] bg-[var(--surface-raised)] p-2 text-[12.5px] file:mr-3 file:rounded-xs file:border-0 file:bg-[var(--accent)] file:px-3 file:py-1.5 file:text-[12px] file:text-[var(--text-inverse)]"
              />
            )}
          </Field>

          <Field label="Tipo de documento" error={errors.category} required>
            {(props) => (
              <Select
                {...props}
                value={data.category}
                onChange={(event) => setData('category', event.target.value)}
              >
                {categories.map((category) => (
                  <option key={category.value} value={category.value}>
                    {category.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>

          <Field
            label="Fecha del documento"
            hint="Opcional. La fecha que figura en el papel, no la de hoy."
            error={errors.document_date}
          >
            {(props) => (
              <Input
                {...props}
                type="date"
                value={data.document_date}
                onChange={(event) => setData('document_date', event.target.value)}
              />
            )}
          </Field>

          <Field label="Nota" hint="Opcional. Máximo 200 caracteres." error={errors.notes}>
            {(props) => (
              <Input
                {...props}
                value={data.notes}
                maxLength={200}
                onChange={(event) => setData('notes', event.target.value)}
              />
            )}
          </Field>

          {progress && (
            <div className="h-1 w-full overflow-hidden rounded-full bg-[var(--surface-hover)]">
              <div
                className="h-full bg-[var(--accent)] transition-[width]"
                style={{ width: `${progress.percentage ?? 0}%` }}
                role="progressbar"
                aria-valuenow={progress.percentage ?? 0}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label="Progreso de la carga"
              />
            </div>
          )}
        </form>
      </DialogContent>
    </Dialog>
  );
}
