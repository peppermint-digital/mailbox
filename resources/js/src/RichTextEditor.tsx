import { Color } from '@tiptap/extension-color';
import { Highlight } from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
import Link from '@tiptap/extension-link';
import Placeholder from '@tiptap/extension-placeholder';
import { Table } from '@tiptap/extension-table';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';
import { TableRow } from '@tiptap/extension-table-row';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyle } from '@tiptap/extension-text-style';
import Underline from '@tiptap/extension-underline';
import { Editor, EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Baseline,
    Bold,
    Code,
    Heading1,
    Heading2,
    Heading3,
    Highlighter,
    ImagePlus,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Loader2,
    Minus,
    Quote,
    Redo2,
    Strikethrough,
    Table as TableIcon,
    Underline as UnderlineIcon,
    Undo2,
    Unlink,
    UploadCloud,
} from 'lucide-react';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Separator } from './ui/separator';
import { Toggle } from './ui/toggle';
import { cn } from './ui/utils';

/**
 * The editor every product writes mail in.
 *
 * ## Why it lives here now
 *
 * It did not. Until 14.10.2026 the CRM had a Tiptap editor and the other
 * products had a plain textarea, and the package's own docblock called that
 * deliberate: "one product uses Tiptap, the next may not."
 *
 * That reasoning does not survive contact with the goal. A toolbar beside a
 * message is the product's own verbs — "take over as a receipt", "create a
 * ticket" — and differs for good reason. Writing is not a verb of any one
 * product: bold is bold in all four. Leaving it per-product meant the same
 * reply looked and behaved differently depending on which window you happened
 * to have open, and a fix for one of them reached nobody else.
 *
 * {@see PlainTextEditor} stays for an installation that wants no rich text —
 * the contract is the same two props, so swapping is a one-line change.
 *
 * ## Words come from the product
 *
 * Every button title, the link prompt and the drop hint are passed in. A
 * package that ships sentences ships a language; see the labels on every other
 * component here.
 */

export interface RichTextEditorLabels {
    bold: string;
    italic: string;
    underline: string;
    strikethrough: string;
    code: string;
    heading1: string;
    heading2: string;
    heading3: string;
    bulletList: string;
    orderedList: string;
    blockquote: string;
    horizontalRule: string;
    alignLeft: string;
    alignCenter: string;
    alignRight: string;
    insertLink: string;
    removeLink: string;
    /** Asked through `window.prompt` — keep it short. */
    linkPrompt: string;
    insertImage: string;
    dropImage: string;
    textColor: string;
    highlight: string;
    insertTable: string;
    undo: string;
    redo: string;
}

export interface RichTextEditorProps {
    value?: string;
    onChange?: (value: string) => void;
    labels: RichTextEditorLabels;
    placeholder?: string;
    className?: string;
    disabled?: boolean;
    minHeight?: number;
    maxHeight?: number;
    showToolbar?: boolean;
    autofocus?: boolean;
    /**
     * Enables image support: shows the image button and accepts drag & drop /
     * paste of image files. Receives the file, must return the hosted image
     * URL. Omitted means no image features at all — not a broken button.
     */
    onImageUpload?: (file: File) => Promise<string>;
    /**
     * The product's own buttons at the end of the toolbar.
     *
     * Formatting is the same everywhere — bold is bold. What is NOT the same
     * is what a product can additionally do with the text: the Manager has an
     * assistant that rephrases and corrects, and dictation. Those hang here
     * instead of forcing a choice between "every product gets them" and "the
     * Manager keeps its own editor".
     *
     * Receives the editor so a button can act on the selection.
     */
    extraActions?: (editor: Editor) => React.ReactNode;
    /**
     * A strip between toolbar and text, for what the product's own buttons
     * have to say: "recording…", "transcribing…", an error from the
     * assistant. Inside the border on purpose — a banner floating above the
     * box reads as belonging to the page, not to the field being typed in.
     */
    statusBar?: React.ReactNode;
}

interface ToolbarButtonProps {
    onClick: () => void;
    isActive?: boolean;
    disabled?: boolean;
    children: React.ReactNode;
    title?: string;
}

const ToolbarButton: React.FC<ToolbarButtonProps> = ({ onClick, isActive = false, disabled = false, children, title }) => (
    <Toggle
        size="sm"
        pressed={isActive}
        onPressedChange={onClick}
        disabled={disabled}
        title={title}
        aria-label={title}
        className="h-8 w-8 p-0 data-[state=on]:bg-accent"
    >
        {children}
    </Toggle>
);

interface EditorToolbarProps {
    editor: Editor | null;
    labels: RichTextEditorLabels;
    onInsertImage?: () => void;
    uploading?: boolean;
    extraActions?: (editor: Editor) => React.ReactNode;
}

const EditorToolbar: React.FC<EditorToolbarProps> = ({ editor, labels, onInsertImage, uploading = false, extraActions }) => {
    const setLink = useCallback(() => {
        if (!editor) return;

        const previousUrl = editor.getAttributes('link').href;
        const url = window.prompt(labels.linkPrompt, previousUrl);

        if (url === null) return;

        if (url === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }

        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }, [editor, labels.linkPrompt]);

    if (!editor) return null;

    return (
        <div className="flex flex-wrap items-center gap-0.5 border-b bg-muted/40 p-1.5" data-slot="rich-text-toolbar">
            <ToolbarButton onClick={() => editor.chain().focus().toggleBold().run()} isActive={editor.isActive('bold')} title={labels.bold}>
                <Bold className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton onClick={() => editor.chain().focus().toggleItalic().run()} isActive={editor.isActive('italic')} title={labels.italic}>
                <Italic className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleUnderline().run()}
                isActive={editor.isActive('underline')}
                title={labels.underline}
            >
                <UnderlineIcon className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleStrike().run()}
                isActive={editor.isActive('strike')}
                title={labels.strikethrough}
            >
                <Strikethrough className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton onClick={() => editor.chain().focus().toggleCode().run()} isActive={editor.isActive('code')} title={labels.code}>
                <Code className="h-4 w-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="mx-1 h-6" />

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}
                isActive={editor.isActive('heading', { level: 1 })}
                title={labels.heading1}
            >
                <Heading1 className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
                isActive={editor.isActive('heading', { level: 2 })}
                title={labels.heading2}
            >
                <Heading2 className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
                isActive={editor.isActive('heading', { level: 3 })}
                title={labels.heading3}
            >
                <Heading3 className="h-4 w-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="mx-1 h-6" />

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleBulletList().run()}
                isActive={editor.isActive('bulletList')}
                title={labels.bulletList}
            >
                <List className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleOrderedList().run()}
                isActive={editor.isActive('orderedList')}
                title={labels.orderedList}
            >
                <ListOrdered className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleBlockquote().run()}
                isActive={editor.isActive('blockquote')}
                title={labels.blockquote}
            >
                <Quote className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton onClick={() => editor.chain().focus().setHorizontalRule().run()} title={labels.horizontalRule}>
                <Minus className="h-4 w-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="mx-1 h-6" />

            <ToolbarButton
                onClick={() => editor.chain().focus().setTextAlign('left').run()}
                isActive={editor.isActive({ textAlign: 'left' })}
                title={labels.alignLeft}
            >
                <AlignLeft className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().setTextAlign('center').run()}
                isActive={editor.isActive({ textAlign: 'center' })}
                title={labels.alignCenter}
            >
                <AlignCenter className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().setTextAlign('right').run()}
                isActive={editor.isActive({ textAlign: 'right' })}
                title={labels.alignRight}
            >
                <AlignRight className="h-4 w-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="mx-1 h-6" />

            <ToolbarButton onClick={setLink} isActive={editor.isActive('link')} title={labels.insertLink}>
                <LinkIcon className="h-4 w-4" />
            </ToolbarButton>

            {editor.isActive('link') && (
                <ToolbarButton onClick={() => editor.chain().focus().unsetLink().run()} title={labels.removeLink}>
                    <Unlink className="h-4 w-4" />
                </ToolbarButton>
            )}

            {onInsertImage && (
                <>
                    <Separator orientation="vertical" className="mx-1 h-6" />
                    <ToolbarButton onClick={onInsertImage} disabled={uploading} title={labels.insertImage}>
                        {uploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <ImagePlus className="h-4 w-4" />}
                    </ToolbarButton>
                </>
            )}

            <Separator orientation="vertical" className="mx-1 h-6" />

            {/* Eine Farbe ist kein Umschalter — deshalb hier ein Feld statt eines Toggles. */}
            <label
                className="relative inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-md hover:bg-muted"
                title={labels.textColor}
            >
                <Baseline className="h-4 w-4" style={{ color: (editor.getAttributes('textStyle').color as string) ?? undefined }} />
                {/*
                    `h-full w-full` zusaetzlich zu `inset-0`, und das ist kein
                    Guertel-und-Hosentraeger (31.10.2026).

                    Ein `input[type=color]` ist ein Widget mit EIGENER
                    Groesse. `position: absolute` mit allen vier Kanten auf 0
                    streckt normale Kaesten — bei einem Widget mit
                    `width: auto` gewinnt die eigene Groesse. Gemessen in
                    Chromium: 50 x 27 statt der 32 x 32 des Labels.

                    Folge: Der unsichtbare Farbwaehler stand 18 px nach rechts
                    ueber sein Label hinaus und lag mit 16 x 27 px auf dem
                    Knopf „Markieren" daneben. Wer dort links tippte, bekam
                    die Farbauswahl statt der Markierung — auf jeder
                    Bildschirmbreite, nicht nur am Telefon.

                    Gefunden von der Breiten-Messreihe, nicht beim Hinsehen:
                    Das Feld ist durchsichtig, man SIEHT den Fehler nicht.
                */}
                <input
                    type="color"
                    aria-label={labels.textColor}
                    className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    value={(editor.getAttributes('textStyle').color as string) ?? '#000000'}
                    onChange={(event) => editor.chain().focus().setColor(event.target.value).run()}
                />
            </label>

            <ToolbarButton
                onClick={() => editor.chain().focus().toggleHighlight().run()}
                isActive={editor.isActive('highlight')}
                title={labels.highlight}
            >
                <Highlighter className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton
                onClick={() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}
                title={labels.insertTable}
            >
                <TableIcon className="h-4 w-4" />
            </ToolbarButton>

            <Separator orientation="vertical" className="mx-1 h-6" />

            <ToolbarButton onClick={() => editor.chain().focus().undo().run()} disabled={!editor.can().undo()} title={labels.undo}>
                <Undo2 className="h-4 w-4" />
            </ToolbarButton>

            <ToolbarButton onClick={() => editor.chain().focus().redo().run()} disabled={!editor.can().redo()} title={labels.redo}>
                <Redo2 className="h-4 w-4" />
            </ToolbarButton>

            {extraActions && (
                <>
                    <Separator orientation="vertical" className="mx-1 h-6" />
                    {extraActions(editor)}
                </>
            )}
        </div>
    );
};

export const RichTextEditor = React.forwardRef<HTMLDivElement, RichTextEditorProps>(
    (
        {
            value = '',
            onChange,
            labels,
            placeholder,
            className,
            disabled = false,
            minHeight = 200,
            maxHeight = 500,
            showToolbar = true,
            autofocus = false,
            onImageUpload,
            extraActions,
            statusBar,
        },
        ref,
    ) => {
        const imageEnabled = Boolean(onImageUpload);
        const [isUploading, setIsUploading] = useState(false);
        const [isDragging, setIsDragging] = useState(false);
        const fileInputRef = useRef<HTMLInputElement>(null);
        const editorRef = useRef<Editor | null>(null);
        const onImageUploadRef = useRef(onImageUpload);
        useEffect(() => {
            onImageUploadRef.current = onImageUpload;
        }, [onImageUpload]);

        // Upload each image file via the callback and insert the returned URL.
        // Uses refs so it stays stable for the editor's paste/drop handlers.
        const uploadAndInsertImages = useCallback(async (files: File[]) => {
            const uploader = onImageUploadRef.current;
            const editorInstance = editorRef.current;
            const images = files.filter((file) => file.type.startsWith('image/'));
            if (!uploader || !editorInstance || images.length === 0) {
                return;
            }
            setIsUploading(true);
            try {
                for (const file of images) {
                    const url = await uploader(file);
                    editorInstance.chain().focus().setImage({ src: url }).run();
                }
            } catch (error) {
                console.error('Image upload failed', error);
            } finally {
                setIsUploading(false);
            }
        }, []);

        const editor = useEditor({
            extensions: [
                StarterKit.configure({
                    heading: {
                        levels: [1, 2, 3],
                    },
                }),
                Placeholder.configure({
                    placeholder: placeholder ?? '',
                    emptyEditorClass: 'is-editor-empty',
                }),
                TextAlign.configure({
                    types: ['heading', 'paragraph'],
                }),
                Link.configure({
                    openOnClick: false,
                    HTMLAttributes: {
                        class: 'text-primary underline cursor-pointer',
                    },
                }),
                Underline,
                // Colour and highlight need TextStyle as their carrier mark.
                TextStyle,
                Color,
                Highlight.configure({ multicolor: false }),
                // Tables travel badly through mail clients unless the borders
                // are inline styles — a class would be stripped on arrival.
                Table.configure({ resizable: false, HTMLAttributes: { style: 'border-collapse: collapse; width: 100%;' } }),
                TableRow,
                TableHeader.configure({ HTMLAttributes: { style: 'border: 1px solid #d4d4d8; padding: 6px; background: #f4f4f5;' } }),
                TableCell.configure({ HTMLAttributes: { style: 'border: 1px solid #d4d4d8; padding: 6px;' } }),
                // Inline styles (not classes) so images stay responsive inside
                // the sent email, where Tailwind is not available.
                Image.configure({
                    inline: false,
                    allowBase64: false,
                    HTMLAttributes: {
                        style: 'max-width: 100%; height: auto; border-radius: 6px;',
                    },
                }),
            ],
            content: value,
            editable: !disabled,
            autofocus,
            onUpdate: ({ editor }) => {
                onChange?.(editor.getHTML());
            },
            editorProps: {
                attributes: {
                    class: cn(
                        'prose prose-sm max-w-none focus:outline-none dark:prose-invert',
                        'prose-headings:mt-4 prose-headings:mb-2 prose-headings:font-semibold',
                        'prose-p:my-2 prose-p:leading-relaxed',
                        'prose-ol:my-2 prose-ul:my-2',
                        'prose-li:my-0.5',
                        'prose-blockquote:border-l-4 prose-blockquote:border-primary/50 prose-blockquote:pl-4 prose-blockquote:italic',
                        'prose-code:rounded prose-code:bg-muted prose-code:px-1.5 prose-code:py-0.5 prose-code:text-sm',
                        'prose-pre:rounded-lg prose-pre:bg-muted prose-pre:p-4',
                        'prose-hr:my-4',
                        'prose-img:my-3 prose-img:rounded-md',
                    ),
                },
                // Paste an image straight from the clipboard (e.g. a screenshot).
                handlePaste: (_view, event) => {
                    if (!onImageUploadRef.current) {
                        return false;
                    }
                    const files = Array.from(event.clipboardData?.files ?? []).filter((file) => file.type.startsWith('image/'));
                    if (files.length === 0) {
                        return false;
                    }
                    event.preventDefault();
                    void uploadAndInsertImages(files);
                    return true;
                },
            },
        });

        // Keep the ref current so the stable upload handler can reach the editor.
        useEffect(() => {
            editorRef.current = editor;
        }, [editor]);

        // Sync external value changes
        useEffect(() => {
            if (editor && value !== editor.getHTML()) {
                editor.commands.setContent(value);
            }
        }, [value, editor]);

        // Handle disabled state
        useEffect(() => {
            if (editor) {
                editor.setEditable(!disabled);
            }
        }, [disabled, editor]);

        const openImagePicker = useCallback(() => fileInputRef.current?.click(), []);

        const handleFileInputChange = useCallback(
            (event: React.ChangeEvent<HTMLInputElement>) => {
                const files = Array.from(event.target.files ?? []);
                void uploadAndInsertImages(files);
                event.target.value = '';
            },
            [uploadAndInsertImages],
        );

        const handleDrop = useCallback(
            (event: React.DragEvent<HTMLDivElement>) => {
                if (!imageEnabled) {
                    return;
                }
                const files = Array.from(event.dataTransfer.files).filter((file) => file.type.startsWith('image/'));
                if (files.length === 0) {
                    return;
                }
                event.preventDefault();
                setIsDragging(false);
                void uploadAndInsertImages(files);
            },
            [imageEnabled, uploadAndInsertImages],
        );

        const handleDragOver = useCallback(
            (event: React.DragEvent<HTMLDivElement>) => {
                if (!imageEnabled || disabled) {
                    return;
                }
                if (Array.from(event.dataTransfer.types).includes('Files')) {
                    event.preventDefault();
                    setIsDragging(true);
                }
            },
            [imageEnabled, disabled],
        );

        const handleDragLeave = useCallback((event: React.DragEvent<HTMLDivElement>) => {
            // Ignore leave events bubbling from child nodes.
            if (event.currentTarget.contains(event.relatedTarget as Node | null)) {
                return;
            }
            setIsDragging(false);
        }, []);

        return (
            <div
                ref={ref}
                /*
                 * `tiptap-email-editor` is what EmailComposerForm looks for
                 * when something is dropped: a file dropped ON the editor is an
                 * inline image, a file dropped anywhere else is an attachment.
                 * The form carried that check since it was written; nothing
                 * ever set the class, so every drop became an attachment.
                 */
                className={cn(
                    'tiptap-email-editor relative rounded-md border bg-background',
                    disabled && 'cursor-not-allowed opacity-50',
                    className,
                )}
                data-slot="rich-text-editor"
                onDragOver={imageEnabled ? handleDragOver : undefined}
                onDragLeave={imageEnabled ? handleDragLeave : undefined}
                onDrop={imageEnabled ? handleDrop : undefined}
            >
                {showToolbar && (
                    <EditorToolbar
                        editor={editor}
                        labels={labels}
                        onInsertImage={imageEnabled ? openImagePicker : undefined}
                        uploading={isUploading}
                        extraActions={extraActions}
                    />
                )}
                {statusBar}
                <EditorContent
                    editor={editor}
                    className="overflow-y-auto px-4 py-3"
                    style={{
                        minHeight: `${minHeight}px`,
                        maxHeight: `${maxHeight}px`,
                    }}
                />

                {imageEnabled && (
                    <input ref={fileInputRef} type="file" accept="image/*" multiple className="hidden" onChange={handleFileInputChange} />
                )}

                {isDragging && (
                    <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-primary bg-primary/10 backdrop-blur-[1px]">
                        <UploadCloud className="h-8 w-8 text-primary" />
                        <span className="text-sm font-medium text-primary">{labels.dropImage}</span>
                    </div>
                )}
            </div>
        );
    },
);

RichTextEditor.displayName = 'RichTextEditor';

export default RichTextEditor;
