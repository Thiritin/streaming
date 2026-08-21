/**
 * Whether inline editing is switched on, per table.
 *
 * Module state rather than a prop: the switch lives in the toolbar (FilterBar) and the
 * controls live in the table (DataTable), and threading it through the page would make
 * every index page carry a flag it has no other use for. Keyed by table name, so turning
 * it on for shows does not turn it on for sources.
 *
 * Deliberately not persisted. It is a mode you are in for a few minutes while reshuffling
 * a running order, and an editable table is not what anyone wants to land on by default.
 */
import { computed, reactive } from 'vue';

const enabled = reactive(new Set());

export function useInlineEdit(name) {
  const key = () => (typeof name === 'function' ? name() : name);

  const isEnabled = computed(() => enabled.has(key()));

  const toggle = () => {
    if (enabled.has(key())) {
      enabled.delete(key());
    } else {
      enabled.add(key());
    }
  };

  return { isEnabled, toggle };
}
