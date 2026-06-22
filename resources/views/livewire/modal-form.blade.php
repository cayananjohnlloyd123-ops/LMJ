<?php

use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

new class extends Component {
    use WithFileUploads;

    // --- Required props ---
    public string $title       = '';
    public array  $fields      = [];   // field definitions
    public string $saveEvent   = '';   // event to dispatch on save e.g. 'save-product'
    public string $buttonLabel = 'Add';

    // --- Internal state ---
    public bool   $open    = false;
    public bool   $loading = false;
    public array  $form    = [];

    // Snapshot of the props as originally passed in (i.e. "Add" mode).
    // openModal() may temporarily override $fields/$title/$buttonLabel for
    // an Edit session (see openModal() below); resetForAdd() uses these to
    // restore the original Add-mode schema/copy afterward.
    //
    // Public (not protected): Livewire only persists PUBLIC properties
    // across the request round-trip (its snapshot/serialization mechanism
    // ignores protected/private state). A protected property here would
    // silently reset to empty on every subsequent request, breaking
    // resetForAdd() the moment it's called after the component's initial
    // page load.
    public array  $addFields      = [];
    public string $addTitle       = '';
    public string $addButtonLabel = '';

    // Temporary uploaded files live here, keyed by field key — kept separate
    // from $form because TemporaryUploadedFile objects can't be serialized
    // into the saveEvent payload sent to the parent. $form holds the
    // resulting storage path (string) once a file field is uploaded/saved.
    public array $files = [];

    // Tracks existing (already-saved) file paths passed in via openModal()
    // for edit sessions, e.g. ['attachment' => 'expenses/attachments/xxx.jpg'].
    // Lets the UI show "current file" + a remove option without re-uploading.
    public array $existingFiles = [];

    public array $removedFiles = [];

    /*
    |--------------------------------------------------------------------------
    | Supported field types:
    |
    |   text, email, number, textarea, select, checkbox, file
    |
    | Field definition shape:
    |   [
    |     'key'         => 'name',           // required
    |     'label'       => 'Full Name',       // required
    |     'type'        => 'text',            // default: text
    |     'placeholder' => '...',             // optional
    |     'required'    => true,              // optional, default false
    |     'step'        => '0.01',            // optional, for number
    |     'options'     => [                  // required for select
    |                        ['value' => 'active', 'label' => 'Active'],
    |                      ],
    |     'span'        => 'full',            // optional: 'full' | 'half' (default full)
    |     'rules'       => ['required','max:255'], // optional validation rules
    |                                                 // STRINGS/ARRAYS ONLY — never put
    |                                                 // Illuminate\Validation\Rule objects
    |                                                 // here, since 'fields' travels through
    |                                                 // Livewire as a public property and
    |                                                 // those objects aren't wire-serializable
    |                                                 // (causes "Property type not supported
    |                                                 // in Livewire for property: [{}]").
    |     'unique'      => [                  // optional, declarative — resolved into a
    |                        'table'  => 'users',     // real Rule::unique() locally inside
    |                        'column' => 'email',     // save() below, never stored back onto
    |                        'ignore' => $userId,     // a public property.
    |                      ],
    |
    |     // 'file' type only:
    |     'accept'      => 'image/*',          // optional, default 'image/*'
    |     'maxSizeKb'    => 4096,               // optional, default 4096 (4MB)
    |     'disk'        => 'public',           // optional, default 'public'
    |     'directory'   => 'uploads',          // optional, default 'uploads'
    |   ]
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->addFields      = $this->fields;
        $this->addTitle       = $this->title;
        $this->addButtonLabel = $this->buttonLabel;

        $this->initForm();
    }

    protected function initForm(array $data = []): void
    {
        $this->existingFiles = [];
        $this->removedFiles  = [];
        $this->files         = [];

        foreach ($this->fields as $field) {
            $key = $field['key'];

            if (($field['type'] ?? 'text') === 'file') {
                // For file fields, $form holds the saved path (string|null);
                // pre-existing path (edit mode) is tracked separately so the
                // UI can show a "current file" preview distinct from a fresh upload.
                $existing = $data[$key] ?? null;
                $this->form[$key] = $existing;
                $this->existingFiles[$key] = $existing;
                continue;
            }

            $this->form[$key] = $data[$key] ?? ($field['default'] ?? '');
        }
    }

    // Called (non-blocking, in background) when the Add button is clicked.
    // The modal already opened instantly client-side via Alpine; this just
    // clears any leftover form data/errors from a previous Edit session.
    //
    // Crucially, it also tells the parent (which owns the "am I editing
    // record #X?" state, e.g. $editingId) to clear that state. Without
    // this, clicking "New" right after closing an Edit session leaves the
    // parent thinking it's still editing — so submitting the "new" form
    // would silently UPDATE the previously-edited record instead of
    // creating a new one.
    //
    // Also restores $fields/$title/$buttonLabel back to whatever was passed
    // in via the component's props (:fields, title, button-label) on initial
    // mount — undoing anything openModal() previously overrode for an Edit
    // session. This matters because $fields/$title only ever get set once,
    // at mount() time; the live `:fields="$fields"` binding on the parent's
    // <livewire:modal-form> tag is NOT re-read on every parent re-render —
    // Livewire only consults child props at initial mount. So switching
    // back from Edit to Add has to be done explicitly here, not by relying
    // on the prop magically reverting.
    public function resetForAdd(): void
    {
        $this->resetErrorBag();
        $this->fields      = $this->addFields;
        $this->title       = $this->addTitle;
        $this->buttonLabel = $this->addButtonLabel;
        $this->initForm();
        $this->open = true; // keep server state in sync
        $this->dispatch('modal-reset');
    }

    // Listens for: $this->dispatch('modal-open', data: [...], fields: [...], title: '...')->to('modal-form');
    //
    // $fields/$title, when passed, OVERRIDE this component's current
    // $fields/$title for the duration of this Edit session — e.g. a
    // password field that's 'required' on Add but 'nullable' on Edit, or an
    // email uniqueness rule that should ignore the record being edited.
    // This is required (not optional) for edit flows: Livewire only reads
    // the parent's :fields="..." prop binding once, at this component's
    // initial mount — a parent re-render with a different $fields value
    // does NOT push through to an already-mounted child on its own. Passing
    // the up-to-date schema explicitly through the event payload is what
    // makes the Edit modal actually reflect Edit-mode rules instead of
    // whatever schema happened to be present at mount.
    #[On('modal-open')]
    public function openModal(array $data = [], ?array $fields = null, ?string $title = null): void
    {
        $this->resetErrorBag();

        if ($fields !== null) {
            $this->fields = $fields;
        }

        if ($title !== null) {
            $this->title = $title;
        }

        $this->initForm($data);
        $this->open = true;
    }

    public function closeModal(): void
    {
        $this->open    = false; // kept in sync for Edit-triggered opens (server-driven)
        $this->loading = false;

        // Restore Add-mode fields/title/buttonLabel — same reasoning as
        // resetForAdd() above. Without this, closing the modal (Cancel/X)
        // after an Edit session leaves $fields/$title permanently stuck on
        // whatever the last Edit session set them to, since nothing else
        // resets them back.
        $this->fields      = $this->addFields;
        $this->title       = $this->addTitle;
        $this->buttonLabel = $this->addButtonLabel;

        $this->initForm();
        $this->resetErrorBag();
        $this->dispatch('modal-reset');
    }

    /**
     * Remove a file field's current value — either an in-progress upload
     * or a previously-saved path (edit mode). Marks it for deletion from
     * disk on save() if it was an existing saved file.
     */
    public function clearFile(string $key): void
    {
        unset($this->files[$key]);

        if (! empty($this->existingFiles[$key])) {
            $this->removedFiles[$key] = $this->existingFiles[$key];
        }

        $this->form[$key] = null;
    }

    protected function fieldsByType(string $type): array
    {
        return array_filter($this->fields, fn ($f) => ($f['type'] ?? 'text') === $type);
    }

    public function save(): void
    {
        // Build validation rules from field definitions
        $rules = [];
        foreach ($this->fields as $field) {
            $type = $field['type'] ?? 'text';

            if ($type === 'file') {
                // Validate the temporary upload itself (files.key), not form.key.
                // 'nullable' unless explicitly required AND no existing file present.
                $required = ($field['required'] ?? false) && empty($this->existingFiles[$field['key']] ?? null);
                $fileRules = $field['rules'] ?? [
                    $required ? 'required' : 'nullable',
                    'file',
                    'max:' . ($field['maxSizeKb'] ?? 4096),
                ];
                $rules['files.' . $field['key']] = $fileRules;
                continue;
            }

            $fieldRules = $field['rules'] ?? ($field['required'] ?? false ? ['required'] : ['nullable']);

            // Resolve any declarative 'unique' definition into a real
            // Rule::unique() instance here — locally, inside this method.
            // This object is only ever used as a transient validation rule
            // and is never assigned back to a public property, so it never
            // has to survive a Livewire wire-serialization round trip.
            if (! empty($field['unique'])) {
                $u = $field['unique'];
                $uniqueRule = Rule::unique($u['table'], $u['column'] ?? $field['key']);

                if (! empty($u['ignore'])) {
                    $uniqueRule = $uniqueRule->ignore($u['ignore'], $u['ignoreColumn'] ?? 'id');
                }

                $fieldRules[] = $uniqueRule;
            }

            $rules['form.' . $field['key']] = $fieldRules;
        }

        $this->validate($rules);

        // Process file uploads: store new files, delete removed/replaced ones,
        // and write the resulting path (or null) into $form before dispatching.
        foreach ($this->fieldsByType('file') as $field) {
            $key = $field['key'];
            $disk = $field['disk'] ?? 'public';

            if (! empty($this->files[$key])) {
                // New file selected — replaces any existing saved file.
                if (! empty($this->existingFiles[$key])) {
                    Storage::disk($disk)->delete($this->existingFiles[$key]);
                }
                $directory = $field['directory'] ?? 'uploads';
                $this->form[$key] = $this->files[$key]->store($directory, $disk);
            } elseif (! empty($this->removedFiles[$key])) {
                Storage::disk($disk)->delete($this->removedFiles[$key]);
                $this->form[$key] = null;
            } else {
                // No change — keep existing saved path as-is.
                $this->form[$key] = $this->existingFiles[$key] ?? null;
            }
        }

        $this->loading = true;

        // Dispatch to parent — parent handles the actual DB operation
        // and should call $this->dispatch('modal-saved')->to('modal-form')
        // or $this->dispatch('modal-error', message: '...')->to('modal-form') when done
        $this->dispatch($this->saveEvent, data: $this->form);
    }

    // Listens for: $this->dispatch('modal-saved')->to('modal-form');
    #[On('modal-saved')]
    public function onSaved(): void
    {
        $this->closeModal();
    }

    // Listens for: $this->dispatch('modal-error', message: '...')->to('modal-form');
    #[On('modal-error')]
    public function onError(string $message): void
    {
        $this->loading = false;
        $this->addError('form', $message);
    }
}; ?>

<div x-data="{ open: @entangle('open') }">

    {{-- Trigger Button — opens instantly client-side, no server round-trip.
         For a blank "Add" form the data is already in memory (defaults from
         initForm() during mount), so there's nothing to fetch from the server. --}}
    <button
        type="button"
        @click="open = true"
        wire:click="resetForAdd"
        class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-opacity"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        <span>{{ $buttonLabel }}</span>
    </button>

    {{-- Modal — always present in the DOM; Alpine x-show toggles visibility
         instantly without waiting on a Livewire round-trip. --}}
    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        x-on:keydown.escape.window="open = false"
    >
            {{-- Backdrop --}}
            <div
                class="fixed inset-0 bg-black/40 transition-opacity"
                @click="open = false"
            ></div>

            {{-- Panel --}}
            <div class="relative w-full max-w-lg rounded-xl bg-white shadow-xl">

                {{-- Loading overlay --}}
                @if ($loading)
                    <div class="absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-white/70 backdrop-blur-sm">
                        <svg class="w-8 h-8 animate-spin text-indigo-600" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                    </div>
                @endif

                <form wire:submit.prevent="save">

                    {{-- Header --}}
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">{{ $title }}</h2>
                        <button
                            type="button"
                            @click="open = false; $wire.closeModal()"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="text-gray-400 hover:text-gray-600 disabled:opacity-40"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {{-- Fields --}}
                    <div class="px-6 py-5 max-h-[65vh] overflow-y-auto">

                        @error('form')
                            <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-600">
                                {{ $message }}
                            </div>
                        @enderror

                        <div class="grid grid-cols-2 gap-4">
                            @foreach ($fields as $field)
                                @php
                                    $key     = $field['key'];
                                    $type    = $field['type'] ?? 'text';
                                    $span    = $field['span'] ?? 'full';
                                    $colSpan = $span === 'half' ? 'col-span-1' : 'col-span-2';
                                @endphp

                                <div class="{{ $colSpan }}">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        {{ $field['label'] }}
                                        @if ($field['required'] ?? false)
                                            <span class="text-red-500">*</span>
                                        @endif
                                    </label>

                                    @if ($type === 'textarea')
                                        <textarea
                                            wire:model="form.{{ $key }}"
                                            rows="{{ $field['rows'] ?? 3 }}"
                                            placeholder="{{ $field['placeholder'] ?? '' }}"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        ></textarea>

                                    @elseif ($type === 'select')
                                        <select
                                            wire:model="form.{{ $key }}"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 cursor-pointer"
                                        >
                                            <option value="">{{ $field['placeholder'] ?? 'Select...' }}</option>
                                            @foreach ($field['options'] ?? [] as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                            @endforeach
                                        </select>

                                    @elseif ($type === 'checkbox')
                                        <div class="flex items-center gap-2 mt-1">
                                            <input
                                                type="checkbox"
                                                wire:model="form.{{ $key }}"
                                                id="field-{{ $key }}"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            >
                                            <label for="field-{{ $key }}" class="text-sm text-gray-600">
                                                {{ $field['hint'] ?? $field['label'] }}
                                            </label>
                                        </div>

                                    @elseif ($type === 'file')
                                        @php
                                            $hasNewUpload = ! empty($files[$key]);
                                            $hasExisting  = ! empty($existingFiles[$key]) && empty($removedFiles[$key]);
                                            $hasFile      = $hasNewUpload || $hasExisting;
                                            $disk         = $field['disk'] ?? 'public';
                                            $maxLabel     = isset($field['maxSizeKb'])
                                                ? ($field['maxSizeKb'] >= 1024 ? round($field['maxSizeKb'] / 1024, 1) . 'MB' : $field['maxSizeKb'] . 'KB')
                                                : '4MB';
                                            $acceptLabel  = ($field['accept'] ?? 'image/*') === 'image/*' ? 'PNG, JPG up to ' . $maxLabel : 'Files up to ' . $maxLabel;
                                        @endphp

                                        <div
                                            x-data="{ dragging: false }"
                                            x-on:dragover.prevent="dragging = true"
                                            x-on:dragleave.prevent="dragging = false"
                                            x-on:drop.prevent="
                                                dragging = false;
                                                if ($event.dataTransfer.files.length > 0) {
                                                    @this.upload('files.{{ $key }}', $event.dataTransfer.files[0]);
                                                }
                                            "
                                        >
                                            @if ($hasFile)
                                                {{-- Filled state: preview card --}}
                                                <div class="relative flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50/60 p-3">
                                                    @php
                                                        $isImage = $hasNewUpload
                                                            ? str_starts_with($files[$key]->getMimeType() ?? '', 'image/')
                                                            : true; // existing path assumed image per accept=image/*
                                                        $thumbUrl = $hasNewUpload
                                                            ? $files[$key]->temporaryUrl()
                                                            : Storage::disk($disk)->url($existingFiles[$key]);
                                                        $fileName = $hasNewUpload
                                                            ? $files[$key]->getClientOriginalName()
                                                            : basename($existingFiles[$key]);
                                                        $fileSize = $hasNewUpload
                                                            ? number_format($files[$key]->getSize() / 1024, 0) . ' KB'
                                                            : null;
                                                    @endphp

                                                    @if ($isImage)
                                                        <img src="{{ $thumbUrl }}" class="h-14 w-14 shrink-0 rounded-lg object-cover ring-1 ring-gray-200" alt="Preview">
                                                    @else
                                                        <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-indigo-50 ring-1 ring-gray-200">
                                                            <svg class="h-6 w-6 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                                        </div>
                                                    @endif

                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-sm font-medium text-gray-700">{{ $fileName }}</p>
                                                        <p class="text-xs text-gray-400">
                                                            @if ($hasNewUpload)
                                                                {{ $fileSize }} &middot; ready to upload
                                                            @else
                                                                Current attachment
                                                            @endif
                                                        </p>
                                                    </div>

                                                    <button
                                                        type="button"
                                                        wire:click="clearFile('{{ $key }}')"
                                                        class="shrink-0 rounded-full p-1.5 text-gray-400 hover:bg-white hover:text-red-500 hover:shadow-sm transition"
                                                        title="Remove file"
                                                    >
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                                    </button>
                                                </div>

                                                {{-- Swap link --}}
                                                <label class="mt-2 inline-flex cursor-pointer items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700">
                                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                                    Replace file
                                                    <input
                                                        x-ref="fileInput{{ $key }}"
                                                        type="file"
                                                        wire:model="files.{{ $key }}"
                                                        accept="{{ $field['accept'] ?? 'image/*' }}"
                                                        class="hidden"
                                                    >
                                                </label>
                                            @else
                                                {{-- Empty state: dropzone --}}
                                                <label
                                                    :class="dragging ? 'border-indigo-400 bg-indigo-50/60' : 'border-gray-300 bg-gray-50/40 hover:border-gray-400 hover:bg-gray-50'"
                                                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-6 text-center transition-colors"
                                                >
                                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-white shadow-sm ring-1 ring-gray-200">
                                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                                                    </div>
                                                    <p class="text-sm text-gray-600">
                                                        <span class="font-medium text-indigo-600">Click to upload</span> or drag and drop
                                                    </p>
                                                    <p class="text-xs text-gray-400">{{ $acceptLabel }}</p>
                                                    <input
                                                        x-ref="fileInput{{ $key }}"
                                                        type="file"
                                                        wire:model="files.{{ $key }}"
                                                        accept="{{ $field['accept'] ?? 'image/*' }}"
                                                        class="hidden"
                                                    >
                                                </label>
                                            @endif

                                            {{-- Upload progress --}}
                                            <div wire:loading wire:target="files.{{ $key }}" class="mt-2 flex items-center gap-2">
                                                <svg class="h-3.5 w-3.5 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                                </svg>
                                                <span class="text-xs text-gray-400">Uploading...</span>
                                            </div>

                                            @error('files.' . $key)
                                                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                                            @enderror
                                        </div>

                                    @else
                                        <input
                                            type="{{ $type }}"
                                            wire:model="form.{{ $key }}"
                                            placeholder="{{ $field['placeholder'] ?? '' }}"
                                            @if (isset($field['step'])) step="{{ $field['step'] }}" @endif
                                            @if (isset($field['min']))  min="{{ $field['min'] }}"   @endif
                                            @if (isset($field['max']))  max="{{ $field['max'] }}"   @endif
                                            class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                    @endif

                                    @error('form.' . $key)
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-200">
                        <button
                            type="button"
                            @click="open = false; $wire.closeModal()"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="save"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-60 transition-opacity"
                        >
                            <svg wire:loading wire:target="save" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                            </svg>
                            <span wire:loading.remove wire:target="save">Save</span>
                            <span wire:loading wire:target="save">Saving...</span>
                        </button>
                    </div>

                </form>
        </div>
    </div>
</div>