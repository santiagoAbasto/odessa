import * as echarts from 'echarts/core';
import { BarChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useRef } from 'react';
import { formatDate } from '@/lib/format';

// Sólo se registra lo que se usa: el bundle de ECharts baja de ~1 MB a ~180 KB.
echarts.use([BarChart, GridComponent, TooltipComponent, CanvasRenderer]);

interface ExpiryPoint {
  week: string;
  count: number;
}

/**
 * Flujo de vencimientos por semana dentro de la ventana preventiva.
 *
 * Sustituye el gráfico del sistema auditado, que mostraba la misma información
 * sin permitir llegar desde ahí al listado. Aquí cada barra es accionable.
 */
export function ExpiryChart({
  data,
  warningDays,
  onSelectWeek,
}: {
  data: ExpiryPoint[];
  warningDays: number;
  onSelectWeek?: (week: string) => void;
}) {
  const container = useRef<HTMLDivElement>(null);
  const chart = useRef<echarts.ECharts | null>(null);

  useEffect(() => {
    if (!container.current) return;

    const instance = echarts.init(container.current, undefined, { renderer: 'canvas' });
    chart.current = instance;

    const styles = getComputedStyle(document.documentElement);
    const accent = styles.getPropertyValue('--accent').trim() || '#2a44a8';
    const textMuted = styles.getPropertyValue('--text-muted').trim() || '#8a90a0';
    const border = styles.getPropertyValue('--border-subtle').trim() || '#e3e5ea';
    const surface = styles.getPropertyValue('--surface-raised').trim() || '#ffffff';
    const textPrimary = styles.getPropertyValue('--text-primary').trim() || '#1a1d26';

    instance.setOption({
      animationDuration: 260,
      grid: { top: 16, right: 8, bottom: 28, left: 34 },
      tooltip: {
        trigger: 'axis',
        backgroundColor: surface,
        borderColor: border,
        textStyle: { color: textPrimary, fontSize: 12 },
        formatter: (params: { name: string; value: number }[]) => {
          const point = params[0];
          if (!point) return '';
          return `Semana del ${formatDate(point.name)}<br/><strong>${point.value}</strong> piezas vencen`;
        },
      },
      xAxis: {
        type: 'category',
        data: data.map((point) => point.week),
        axisLabel: {
          color: textMuted,
          fontSize: 10,
          formatter: (value: string) => formatDate(value, 'dd/MM'),
        },
        axisLine: { lineStyle: { color: border } },
        axisTick: { show: false },
      },
      yAxis: {
        type: 'value',
        minInterval: 1,
        axisLabel: { color: textMuted, fontSize: 10 },
        splitLine: { lineStyle: { color: border, type: 'dashed' } },
      },
      series: [
        {
          type: 'bar',
          data: data.map((point) => point.count),
          barMaxWidth: 26,
          itemStyle: { color: accent },
          emphasis: { itemStyle: { color: accent, opacity: 0.82 } },
          cursor: onSelectWeek ? 'pointer' : 'default',
        },
      ],
    });

    if (onSelectWeek) {
      instance.on('click', (event) => {
        if (typeof event.name === 'string') onSelectWeek(event.name);
      });
    }

    const observer = new ResizeObserver(() => instance.resize());
    observer.observe(container.current);

    return () => {
      observer.disconnect();
      instance.dispose();
      chart.current = null;
    };
  }, [data, onSelectWeek]);

  if (data.length === 0) {
    return (
      <div className="flex h-56 items-center justify-center px-4 text-center">
        <p className="text-[12.5px] text-[var(--text-secondary)]">
          Ninguna pieza vence en los próximos {warningDays} días.
        </p>
      </div>
    );
  }

  return (
    <>
      <div ref={container} className="h-56 w-full" role="img" aria-label="Gráfico de vencimientos por semana" />
      {/* Alternativa textual del gráfico para lectores de pantalla (WCAG 1.1.1). */}
      <table className="sr-only">
        <caption>Piezas que vencen por semana</caption>
        <thead>
          <tr>
            <th scope="col">Semana</th>
            <th scope="col">Piezas</th>
          </tr>
        </thead>
        <tbody>
          {data.map((point) => (
            <tr key={point.week}>
              <td>{formatDate(point.week)}</td>
              <td>{point.count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </>
  );
}
