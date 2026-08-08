import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import TextEditor from 'flarum/common/components/TextEditor';
import Button from 'flarum/common/components/Button';
import type ItemList from 'flarum/common/utils/ItemList';

export { default as extend } from './extend';

import UnlockModal from './components/UnlockModal';
import { attemptAutoUnlock } from './utils/autoUnlock';
import { getPostId } from './utils/unlock';
import { setupRealtimeUpdates, setupTimeUnlocks } from './utils/realtime';

const REQUIREMENT_KEYS = ['time', 'like', 'reply', 'follow', 'minlikes'] as const;

export interface CipherRequirement {
  key: string;
  met: boolean;
  message: string;
}

function requirementDataKey(prefix: string, key: string): string {
  return `${prefix}${key.charAt(0).toUpperCase()}${key.slice(1)}`;
}

/**
 * Collect the configured visibility requirements of a locked block, as
 * rendered by the server, along with their current satisfaction status.
 */
function boxRequirements(box: HTMLElement): CipherRequirement[] {
  return REQUIREMENT_KEYS.map((key) => {
    const message = box.dataset[requirementDataKey('cipherMsg', key)];
    const met = box.dataset[requirementDataKey('cipherReq', key)];

    if (!message) return null;

    return { key, met: met === '1', message };
  }).filter((requirement): requirement is CipherRequirement => Boolean(requirement));
}

app.initializers.add('lcoy-cipher', () => {
  // Delegate clicks on the server-rendered unlock buttons, opening the modal
  // for the corresponding protected block.
  document.addEventListener('click', (e: MouseEvent) => {
    const button = (e.target as HTMLElement).closest<HTMLElement>('.Cipher-unlock-button');

    if (!button) return;

    e.preventDefault();

    const box = button.closest<HTMLElement>('.Cipher-box-locked');

    if (!box) return;

    const postId = getPostId(box);
    const cipherId = box.dataset.cipherId;

    if (postId != null && cipherId) {
      app.modal.show(UnlockModal, { postId, cipherId, requirements: boxRequirements(box) });
    }
  });

  // Composer toolbar button that inserts the [protected] BBCode, prompting for
  // a password and optional visibility conditions.
  extend(TextEditor.prototype, 'toolbarItems', function (this: TextEditor, items: ItemList) {
    items.add(
      'cipher',
      <Button
        className="Button Button--icon Button--link"
        icon="fas fa-lock"
        title={String(app.translator.trans('lcoy-cipher.forum.insert_protected'))}
        onclick={() => {
          const editor = this.attrs.composer.editor;
          const [start, end] = editor.getSelectionRange();
          const selected =
            editor.el.value.slice(start, end) || String(app.translator.trans('lcoy-cipher.forum.placeholder_content'));

          const password =
            window.prompt(String(app.translator.trans('lcoy-cipher.forum.insert_password_prompt')), '') || '';
          const options =
            window.prompt(String(app.translator.trans('lcoy-cipher.forum.insert_options_prompt')), '') || '';

          const attrs: string[] = [`password="${password}"`];

          options.split(/[,，]/).forEach((raw) => {
            const option = raw.trim();

            if (!option) return;

            const eq = option.indexOf('=');

            if (eq > -1) {
              attrs.push(`${option.slice(0, eq).trim()}="${option.slice(eq + 1).trim()}"`);
            } else {
              attrs.push(`${option}="1"`);
            }
          });

          const prefix = `[protected ${attrs.join(' ')}]`;
          const template = `${prefix}${selected}[/protected]`;

          editor.insertBetween(start, end, template, false);
          editor.moveCursorTo(start + prefix.length);
        }}
      />,
      10
    );
  });

  // Silently re-unlock blocks the visitor already unlocked during this session.
  attemptAutoUnlock();

  // Real-time: subscribe to WebSocket updates and schedule time-gated unlocks.
  setupRealtimeUpdates();
  setupTimeUnlocks();

  // New posts can load lazily (infinite scroll), so re-arm time timers whenever
  // the DOM gains locked cards.
  const observer = new MutationObserver(() => setupTimeUnlocks());

  observer.observe(document.body, { childList: true, subtree: true });
});
