/**
 * Плашки причин у врача — одного вида на всех экранах (очередь, список, карточка, чат):
 * противопоказание без решения — опасность, сигнал о приёме и непросмотренный побочный
 * эффект — предупреждение, новые сообщения — мятная. Иконка повторяет смысл цвета.
 */
export const DANGER_CHIP =
    'inline-flex items-start gap-1.5 rounded-md border border-danger-border bg-danger-subtle px-2 py-1 font-medium text-danger';

export const WARNING_CHIP =
    'inline-flex items-start gap-1.5 rounded-md border border-warning-border bg-warning-subtle px-2 py-1 font-medium text-warning';

export const MESSAGE_CHIP =
    'inline-flex items-center gap-1.5 rounded-md bg-secondary px-2 py-1 font-medium text-secondary-foreground hover:bg-secondary-hover';

export const CHIP_ICON = 'mt-0.5 size-4 shrink-0';
