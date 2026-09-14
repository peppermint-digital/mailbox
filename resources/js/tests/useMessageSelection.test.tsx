import { describe, expect, it } from 'vitest';
import { act } from 'react';
import { useMessageSelection, type MessageSelection } from '../src/useMessageSelection';
import type { RowMessage, Thread } from '../src/rows';
import { render } from './support/render';

function msg(uid: number): RowMessage {
    return { uid, subject: 'B', from_address: '', from_name: '', date: '', has_attachments: false,
        attachment_count: 0, is_read: true, is_flagged: false, preview: '' };
}

const kette: Thread<RowMessage> = {
    thread_id: 't1', subject: 'K', message_count: 3, latest_date: null, has_unread: false,
    messages: [msg(1), msg(2), msg(3)], latest: msg(3),
};

function mount(opts: { grouped?: boolean; isSearchMode?: boolean } = {}) {
    const ref: { current: MessageSelection } = { current: null as never };
    function Probe() {
        ref.current = useMessageSelection({ threads: [kette], grouped: opts.grouped ?? false, isSearchMode: opts.isSearchMode ?? false });
        return null;
    }
    const { unmount } = render(<Probe />);
    return { ref, unmount };
}

describe('useMessageSelection', () => {
    it('ticks and unticks', () => {
        const { ref, unmount } = mount();

        act(() => { ref.current.toggle(1, true); });
        expect([...ref.current.ticked]).toEqual([1]);
        expect(ref.current.any).toBe(true);

        act(() => { ref.current.toggle(1, false); });
        expect(ref.current.any).toBe(false);
        unmount();
    });

    it('means exactly the ticked message outside conversation mode', () => {
        const { ref, unmount } = mount();

        act(() => { ref.current.toggle(3, true); });

        expect(ref.current.targets).toEqual([3]);
        unmount();
    });

    it('means the whole conversation in conversation mode', () => {
        // Nobody ticking a row marked "3" means one of three.
        const { ref, unmount } = mount({ grouped: true });

        act(() => { ref.current.toggle(3, true); });

        expect(ref.current.targets.sort()).toEqual([1, 2, 3]);
        expect([...ref.current.ticked]).toEqual([3]);
        unmount();
    });

    it('means one message again in search, where hits are not conversations', () => {
        const { ref, unmount } = mount({ grouped: true, isSearchMode: true });

        act(() => { ref.current.toggle(3, true); });

        expect(ref.current.targets).toEqual([3]);
        unmount();
    });

    it('clears everything at once', () => {
        const { ref, unmount } = mount();

        act(() => { ref.current.toggle(1, true); ref.current.toggle(2, true); });
        act(() => { ref.current.clear(); });

        expect(ref.current.any).toBe(false);
        expect(ref.current.targets).toEqual([]);
        unmount();
    });

    it('opens and closes a conversation', () => {
        const { ref, unmount } = mount();

        act(() => { ref.current.toggleThread('t1'); });
        expect(ref.current.expanded.has('t1')).toBe(true);

        act(() => { ref.current.toggleThread('t1'); });
        expect(ref.current.expanded.has('t1')).toBe(false);
        unmount();
    });

    it('closes all conversations at once — their keys mean nothing in the other mode', () => {
        const { ref, unmount } = mount();

        act(() => { ref.current.toggleThread('t1'); ref.current.toggleThread('t2'); });
        act(() => { ref.current.collapseAll(); });

        expect(ref.current.expanded.size).toBe(0);
        unmount();
    });
});
