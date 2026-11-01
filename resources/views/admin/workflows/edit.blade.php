@extends('layouts.admin')

@php
    $categoryColors = [
        'Ablauf' => ['border' => '#6b7280', 'chip' => 'bg-gray-100 text-gray-700'],
        'Bedingung' => ['border' => '#2563eb', 'chip' => 'bg-blue-100 text-blue-700'],
        'Prüfung' => ['border' => '#d97706', 'chip' => 'bg-amber-100 text-amber-700'],
        'Interaktion' => ['border' => '#7c3aed', 'chip' => 'bg-violet-100 text-violet-700'],
        'Aktion' => ['border' => '#059669', 'chip' => 'bg-emerald-100 text-emerald-700'],
    ];
    $typesByCategory = $types->groupBy('category');
    $cssKey = fn (string $key) => 'wf-t-'.preg_replace('/[^a-z0-9]+/', '-', $key);
@endphp

@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/drawflow/drawflow-0.0.60.min.css') }}">
<style>
    #wf-canvas { background-color: transparent; background-image: radial-gradient(rgba(127,127,127,.25) 1px, transparent 1px); background-size: 20px 20px; }
    #wf-canvas .drawflow .drawflow-node { background: transparent; border: 0; box-shadow: none; padding: 0; width: 220px; min-height: 0; color: inherit; }
    #wf-canvas .drawflow .drawflow-node .outputs { position: absolute; right: 0; top: 0; bottom: 0; display: flex; flex-direction: column; justify-content: center; gap: 10px; }
    #wf-canvas .drawflow .drawflow-node .wf-card { min-height: 72px; padding-right: 64px; }
    #wf-canvas .drawflow .drawflow-node.selected { background: transparent; }
    #wf-canvas .drawflow .drawflow-node.selected .wf-card { box-shadow: 0 0 0 2px #ef4444; }
    #wf-canvas .drawflow .drawflow-node.wf-has-error .wf-card { box-shadow: 0 0 0 2px #dc2626; }
    #wf-canvas .drawflow .drawflow-node.wf-in-path .wf-card { box-shadow: 0 0 0 3px #10b981; }
    #wf-canvas .drawflow .drawflow-node .inputs, #wf-canvas .drawflow .drawflow-node .outputs { width: 0; }
    #wf-canvas .drawflow .drawflow-node .input, #wf-canvas .drawflow .drawflow-node .output { width: 14px; height: 14px; border: 2px solid #9ca3af; background: #fff; }
    #wf-canvas .drawflow .drawflow-node .input { left: -8px; }
    #wf-canvas .drawflow .drawflow-node .output { right: -8px; position: relative; }
    #wf-canvas .drawflow .drawflow-node .output:hover, #wf-canvas .drawflow .drawflow-node .input:hover { background: #ef4444; border-color: #ef4444; }
    #wf-canvas .drawflow .drawflow-node .output::after { position: absolute; right: 14px; top: -3px; padding: 1px 5px; border-radius: 9999px; font-size: 11px; font-weight: 600; line-height: 14px; white-space: nowrap; color: #374151; background: #e5e7eb; pointer-events: none; }
    html.dark #wf-canvas .drawflow .drawflow-node .output::after { color: #e5e7eb; background: #3a4150; }
    #wf-canvas .drawflow .connection .main-path { stroke: #9ca3af; stroke-width: 3px; }
    #wf-canvas .drawflow .connection .main-path:hover, #wf-canvas .drawflow .connection .main-path.selected { stroke: #ef4444; }
    #wf-canvas .drawflow-delete { background: #ef4444; border: 2px solid #fff; color: #fff; line-height: 26px; }
    @foreach ($types as $type)
        @foreach (array_values($type['outputs']) as $i => $label)
            #wf-canvas .{{ $cssKey($type['key']) }} .outputs .output:nth-child({{ $i + 1 }})::after { content: "{{ $label }}"; }
        @endforeach
    @endforeach
    html.dark #wf-canvas .drawflow .drawflow-node .input, html.dark #wf-canvas .drawflow .drawflow-node .output { background: #1e222b; }
    html.dark #wf-canvas .wf-card { background-color: #2a303b; border-color: #3a4150; }
</style>
@endpush

@section('admin-content')
<div x-data="workflowEditor()">
    <x-page-header :title="$workflow->name" :back="route('admin.workflows.index')" back-label="Alle Workflows"
        :description="\App\Models\Workflow::phaseLabel($workflow->phase).($workflow->application ? ' · '.$workflow->application->name : ($isGlobal ? ' · Standard für alle Anwendungen' : ''))">
        <x-slot:actions>
            @if ($workflow->phase !== 'event')
                <form method="POST" action="{{ route('admin.workflows.reset', $workflow) }}"
                      onsubmit="return confirm('Den Workflow wirklich {{ $workflow->application_id ? 'auf den globalen Standard' : 'auf den mitgelieferten Standard' }} zurücksetzen? Die aktuelle Fassung bleibt als Version erhalten.')">
                    @csrf
                    <x-button type="submit" variant="secondary" size="sm"><x-icon name="arrow-path" class="h-4 w-4" />Zurücksetzen</x-button>
                </form>
            @endif
            <x-button type="button" size="sm" x-on:click="save()" x-bind:disabled="saving">
                <x-icon name="check" class="h-4 w-4" /><span x-text="saving ? 'Speichert …' : 'Speichern'">Speichern</span>
            </x-button>
        </x-slot:actions>
    </x-page-header>


    <div x-show="message" x-cloak class="mb-4 rounded-md px-4 py-3 text-sm"
         :class="messageType === 'success' ? 'border border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200' : 'border border-red-500/40 bg-red-500/10 text-red-800 dark:text-red-200'">
        <p x-text="message"></p>
        <ul class="mt-1 list-disc pl-5" x-show="generalErrors.length">
            <template x-for="error in generalErrors"><li x-text="error"></li></template>
        </ul>
    </div>

    <div class="mb-4 grid gap-4 md:grid-cols-[1fr_2fr_auto] md:items-end">
        <div>
            <x-input-label value="Name" />
            <x-input type="text" x-model="name" />
        </div>
        <div>
            <x-input-label value="Beschreibung" />
            <x-input type="text" x-model="description" placeholder="Wofür ist dieser Ablauf da?" />
        </div>
        @unless ($isGlobal)
            <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
                <x-checkbox x-model="isActive" />
                Aktiv
            </label>
        @endunless
    </div>

    <div class="grid gap-4 lg:grid-cols-[13rem_1fr] min-[1800px]:grid-cols-[13rem_1fr_19rem]">
        {{-- Palette --}}
        <x-card class="lg:max-h-[680px] lg:overflow-y-auto">
            <p class="mb-3 text-xs text-gray-500">Bausteine auf die Fläche ziehen oder antippen.</p>
            @foreach ($typesByCategory as $category => $categoryTypes)
                <h4 class="mt-3 mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 first:mt-0">{{ $category }}</h4>
                <div class="space-y-1.5">
                    @foreach ($categoryTypes as $type)
                        <div draggable="true"
                             x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $type['key'] }}')"
                             x-on:click="addAtCenter('{{ $type['key'] }}')"
                             title="{{ $type['description'] }}"
                             class="cursor-grab select-none rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-sm text-gray-800 hover:border-gray-400 active:cursor-grabbing"
                             style="border-left: 4px solid {{ $categoryColors[$category]['border'] ?? '#6b7280' }}">
                            {{ $type['label'] }}
                        </div>
                    @endforeach
                </div>
            @endforeach
        </x-card>

        {{-- Zeichenfläche --}}
        <div class="relative overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div id="wf-canvas" class="h-[520px] lg:h-[680px] w-full"
                 x-on:dragover.prevent x-on:drop.prevent="drop($event)"></div>
            <div class="absolute bottom-3 left-3 flex gap-1">
                <button type="button" x-on:click="zoom('in')" class="rounded border border-gray-300 bg-white px-2 py-0.5 text-sm text-gray-700 hover:bg-gray-50" title="Vergrößern">+</button>
                <button type="button" x-on:click="zoom('out')" class="rounded border border-gray-300 bg-white px-2 py-0.5 text-sm text-gray-700 hover:bg-gray-50" title="Verkleinern">−</button>
                <button type="button" x-on:click="zoom('reset')" class="rounded border border-gray-300 bg-white px-2 py-0.5 text-xs text-gray-700 hover:bg-gray-50">Einpassen</button>
            </div>
            <p class="pointer-events-none absolute right-3 bottom-3 hidden text-xs text-gray-400 sm:block">Ausgang (rechts) auf Eingang (links) ziehen · Entf löscht</p>
        </div>

        {{-- Eigenschaften / Testlauf --}}
        <div class="grid gap-4 md:grid-cols-2 lg:col-span-2 min-[1800px]:col-span-1 min-[1800px]:grid-cols-1 min-[1800px]:content-start">
            <x-card title="Eigenschaften">
                <template x-if="! selected">
                    <p class="text-sm text-gray-500">Baustein anklicken, um ihn einzustellen.</p>
                </template>
                <template x-if="selected">
                    <div class="space-y-3">
                        <div>
                            <p class="font-semibold text-gray-900" x-text="selectedType().label"></p>
                            <p class="mt-0.5 text-xs text-gray-500" x-text="selectedType().description"></p>
                        </div>
                        <template x-for="field in selectedType().fields" :key="field.key">
                            <div>
                                <template x-if="field.type !== 'checkbox'">
                                    <label class="mb-1 block text-sm font-medium text-gray-700" x-text="field.label + (field.required ? ' *' : '')"></label>
                                </template>
                                <template x-if="field.type === 'text' || field.type === 'number'">
                                    <input :type="field.type" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-laravel-500 focus:ring-laravel-500"
                                           :value="config[field.key] ?? ''" x-on:input="setField(field.key, $event.target.value)">
                                </template>
                                <template x-if="field.type === 'textarea' || field.type === 'lines'">
                                    <textarea rows="4" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-laravel-500 focus:ring-laravel-500"
                                              :value="config[field.key] ?? ''" x-on:input="setField(field.key, $event.target.value)"></textarea>
                                </template>
                                <template x-if="field.type === 'checkbox'">
                                    <label class="flex items-start gap-2 text-sm text-gray-700">
                                        <input type="checkbox" class="mt-0.5 rounded border-gray-300 text-laravel-600 focus:ring-laravel-500"
                                               :checked="!! config[field.key]" x-on:change="setField(field.key, $event.target.checked)">
                                        <span x-text="field.label"></span>
                                    </label>
                                </template>
                                <template x-if="field.type === 'select'">
                                    <select class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-laravel-500 focus:ring-laravel-500"
                                            x-on:change="setField(field.key, $event.target.value)">
                                        <template x-for="[value, label] in Object.entries(field.options || {})" :key="value">
                                            <option :value="value" :selected="String(config[field.key]) === String(value)" x-text="label"></option>
                                        </template>
                                    </select>
                                </template>
                                <template x-if="field.type === 'multiselect'">
                                    <div class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-gray-200 p-2">
                                        <template x-for="[value, label] in Object.entries(field.options || {})" :key="value">
                                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                                <input type="checkbox" class="rounded border-gray-300 text-laravel-600 focus:ring-laravel-500"
                                                       :checked="(config[field.key] || []).map(String).includes(String(value))"
                                                       x-on:change="toggleMulti(field.key, value, $event.target.checked)">
                                                <span x-text="label"></span>
                                            </label>
                                        </template>
                                        <p class="text-xs text-gray-400" x-show="! Object.keys(field.options || {}).length">Keine Einträge vorhanden.</p>
                                    </div>
                                </template>
                                <p class="mt-1 text-xs text-gray-500" x-show="field.help" x-text="field.help"></p>
                            </div>
                        </template>
                        <ul class="list-disc space-y-0.5 pl-5 text-sm text-red-600" x-show="(nodeErrors[selectedNodeId()] || []).length">
                            <template x-for="error in (nodeErrors[selectedNodeId()] || [])"><li x-text="error"></li></template>
                        </ul>
                        <button type="button" x-on:click="removeSelected()" class="text-sm font-medium text-red-600 hover:text-red-700">Baustein entfernen</button>
                    </div>
                </template>
            </x-card>

            <x-card title="Testlauf" description="Spielt den aktuellen Stand (auch ungespeichert) für ein Konto durch, ohne etwas auszulösen.">
                <div class="space-y-2">
                    <div>
                        <x-input-label value="Benutzername oder E-Mail" />
                        <x-input type="text" x-model="sim.username" placeholder="mmustermann" />
                    </div>
                    @if (! $workflow->application_id)
                        <div>
                            <x-input-label value="Anwendung" />
                            <select x-model="sim.application_id" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-laravel-500 focus:ring-laravel-500">
                                <option value="">{{ $workflow->phase === 'login' ? '– direkt am Portal –' : '– keine –' }}</option>
                                @foreach ($applications as $application)
                                    <option value="{{ $application->id }}">{{ $application->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @if ($workflow->phase === 'event')
                        <div>
                            <x-input-label value="Ereignis" />
                            <select x-model="sim.event" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-laravel-500 focus:ring-laravel-500">
                                @foreach (\App\Workflow\EventTriggers::EVENTS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div>
                            <x-input-label value="Angemeldet mit" />
                            <select x-model="sim.method" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-laravel-500 focus:ring-laravel-500">
                                @foreach (\App\Workflow\Nodes\MethodCondition::METHODS as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <x-input-label value="IP-Adresse" />
                            <x-input type="text" x-model="sim.ip" placeholder="10.0.0.5" />
                        </div>
                        <div>
                            <x-input-label value="Zeitpunkt" />
                            <x-input type="datetime-local" x-model="sim.time" />
                        </div>
                    </div>
                    <x-button type="button" variant="secondary" size="sm" class="w-full" x-on:click="simulate()" x-bind:disabled="simulating">
                        <span x-text="simulating ? 'Läuft …' : 'Testlauf starten'">Testlauf starten</span>
                    </x-button>

                    <template x-if="simResult">
                        <div class="mt-2 rounded-md border p-3 text-sm"
                             :class="simResult.outcome === 'allow' ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200' : 'border-red-500/40 bg-red-500/10 text-red-800 dark:text-red-200'">
                            <p class="font-semibold" x-text="simResult.outcome === 'allow' ? '{{ $workflow->phase === 'event' ? 'Ablauf beendet' : 'Erlaubt' }}' : (simResult.outcome === 'deny' ? 'Abgelehnt' : 'Fehler')"></p>
                            <p class="mt-0.5" x-show="simResult.message" x-text="simResult.message"></p>
                            <ol class="mt-2 list-decimal space-y-0.5 pl-5 text-xs">
                                <template x-for="step in simResult.steps">
                                    <li>
                                        <span x-text="step.label"></span><span x-show="step.output" x-text="' → ' + (outputLabel(step) || '')"></span>
                                        <span x-show="step.note" class="block opacity-75" x-text="step.note"></span>
                                    </li>
                                </template>
                            </ol>
                        </div>
                    </template>
                </div>
            </x-card>
        </div>
    </div>

    @if ($versions->isNotEmpty())
        <x-card title="Frühere Versionen" class="mt-6">
            <ul class="divide-y divide-gray-100">
                @foreach ($versions as $version)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span class="text-gray-700">Version {{ $version->version }} · {{ $version->created_at?->format('d.m.Y H:i') }}{{ $version->author ? ' · '.$version->author->name : '' }}</span>
                        <form method="POST" action="{{ route('admin.workflows.restore', [$workflow, $version]) }}"
                              onsubmit="return confirm('Version {{ $version->version }} wiederherstellen? Ungespeicherte Änderungen gehen verloren.')">
                            @csrf
                            <x-button type="submit" variant="link" size="sm">Wiederherstellen</x-button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @if (! $isGlobal)
        <x-danger-zone class="mt-6">
            <p class="w-full text-sm text-red-700">
                {{ $workflow->application ? 'Danach gilt für '.$workflow->application->name.' wieder der globale Ablauf.' : 'Der Ereignis-Workflow wird endgültig gelöscht.' }}
            </p>
            <form method="POST" action="{{ route('admin.workflows.destroy', $workflow) }}" onsubmit="return confirm('Workflow wirklich löschen?')">
                @csrf @method('DELETE')
                <x-button type="submit" variant="danger" size="sm"><x-icon name="trash" class="h-4 w-4" />Workflow löschen</x-button>
            </form>
        </x-danger-zone>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/drawflow/drawflow-0.0.60.min.js') }}"></script>
<script>
    function workflowEditor() {
        const TYPES = Object.fromEntries(@json($types).map(t => [t.key, t]));
        const COLORS = @json($categoryColors);
        const GRAPH = @json($workflow->graph);
        const escapeHtml = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const cssKey = key => 'wf-t-' + key.replace(/[^a-z0-9]+/g, '-');
        let editor = null;

        return {
            zoom(dir) { dir === "in" ? editor.zoom_in() : (dir === "out" ? editor.zoom_out() : this.fit()); },

            // Alle Bausteine sichtbar machen (Zoom und Verschiebung anpassen).
            fit() {
                const nodes = Object.values(editor.export().drawflow.Home.data);
                if (!nodes.length) return;
                const els = nodes.map(n => document.getElementById('node-' + n.id));
                const minX = Math.min(...nodes.map(n => n.pos_x)), minY = Math.min(...nodes.map(n => n.pos_y));
                const maxX = Math.max(...nodes.map((n, i) => n.pos_x + (els[i]?.offsetWidth || 220) + 60));
                const maxY = Math.max(...nodes.map((n, i) => n.pos_y + (els[i]?.offsetHeight || 80)));
                const rect = editor.container.getBoundingClientRect(), pad = 40;
                const zoom = Math.max(0.4, Math.min(1, (rect.width - 2 * pad) / (maxX - minX), (rect.height - 2 * pad) / (maxY - minY)));
                // Drawflow skaliert um die Mitte der Fläche (transform-origin 50 % 50 %).
                const cx = editor.precanvas.clientWidth / 2, cy = editor.precanvas.clientHeight / 2;
                editor.zoom = zoom;
                editor.canvas_x = pad - minX * zoom + ((rect.width - 2 * pad) - (maxX - minX) * zoom) / 2 - cx * (1 - zoom);
                editor.canvas_y = pad - minY * zoom + Math.max(0, ((rect.height - 2 * pad) - (maxY - minY) * zoom) / 3) - cy * (1 - zoom);
                editor.precanvas.style.transform = `translate(${editor.canvas_x}px, ${editor.canvas_y}px) scale(${zoom})`;
                editor.zoom_last_value = zoom;
            },
            name: @json($workflow->name),
            description: @json($workflow->description ?? ''),
            isActive: @json((bool) $workflow->is_active),
            selected: null,
            config: {},
            saving: false,
            message: '',
            messageType: 'success',
            generalErrors: [],
            nodeErrors: {},
            simulating: false,
            simResult: null,
            sim: { username: '', application_id: @json((string) ($workflow->application_id ?? '')), method: 'local', event: @json(array_key_first(\App\Workflow\EventTriggers::EVENTS)), ip: '', time: '' },

            init() {
                const el = document.getElementById('wf-canvas');
                editor = new Drawflow(el);
                editor.reroute = false;
                editor.start();

                const ids = {};
                (GRAPH.nodes || []).forEach(n => { ids[n.id] = this.addNode(n.type, n.position?.x ?? 0, n.position?.y ?? 0, n.config || {}, n.id); });
                (GRAPH.edges || []).forEach(e => {
                    const type = TYPES[(GRAPH.nodes.find(n => n.id === e.from) || {}).type];
                    const index = type ? Object.keys(type.outputs).indexOf(e.output) : -1;
                    if (ids[e.from] && ids[e.to] && index >= 0) {
                        editor.addConnection(ids[e.from], ids[e.to], 'output_' + (index + 1), 'input_1');
                    }
                });

                requestAnimationFrame(() => this.fit());

                editor.on('nodeSelected', id => this.select(id));
                editor.on('nodeUnselected', () => { this.selected = null; });
                editor.on('nodeRemoved', id => { if (String(this.selected) === String(id)) this.selected = null; });
                // Pro Ausgang nur eine Verbindung: die ältere ersetzen.
                editor.on('connectionCreated', c => {
                    const conns = editor.getNodeFromId(c.output_id).outputs[c.output_class].connections;
                    conns.slice(0, -1).forEach(old => editor.removeSingleConnection(c.output_id, old.node, c.output_class, old.output));
                });
            },

            nodeHtml(typeKey, config) {
                const type = TYPES[typeKey];
                const color = COLORS[type.category] || COLORS['Ablauf'];
                const summary = this.summary(type, config);
                return `<div class="wf-card rounded-lg border border-gray-200 bg-white shadow-sm" style="border-left:4px solid ${color.border}">
                    <div class="px-3 py-2">
                        <span class="inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${color.chip}">${escapeHtml(type.category)}</span>
                        <p class="mt-1 text-sm font-semibold text-gray-900">${escapeHtml(type.label)}</p>
                        ${summary ? `<p class="mt-0.5 text-xs text-gray-500 break-words">${escapeHtml(summary)}</p>` : ''}
                    </div>
                </div>`;
            },

            summary(type, config) {
                const parts = [];
                (type.fields || []).forEach(f => {
                    const v = config[f.key];
                    if (v === undefined || v === '' || v === null || f.type === 'checkbox') return;
                    if (f.type === 'multiselect' || f.type === 'select') {
                        const values = Array.isArray(v) ? v : [v];
                        const labels = values.map(x => (f.options || {})[x] ?? x);
                        if (labels.length) parts.push(labels.join(', '));
                    } else if (f.key !== 'secret') {
                        parts.push(String(v).split(/\r?\n/).filter(Boolean).join(', '));
                    }
                });
                const text = parts.join(' · ');
                return text.length > 90 ? text.slice(0, 87) + '…' : text;
            },

            addNode(typeKey, x, y, config = {}, nodeId = null) {
                const type = TYPES[typeKey];
                if (!type) return null;
                const filled = Object.assign({}, ...(type.fields || []).map(f => f.default !== undefined ? { [f.key]: f.default } : {}), config);
                const outputs = Object.keys(type.outputs).length;
                return editor.addNode(typeKey, type.entry ? 0 : 1, outputs, x, y, 'wf-node ' + cssKey(typeKey),
                    { nodeId: nodeId || ('n' + Math.random().toString(36).slice(2, 9)), type: typeKey, config: filled },
                    this.nodeHtml(typeKey, filled));
            },

            drop(event) {
                const typeKey = event.dataTransfer.getData('text/plain');
                const e = editor, pc = e.precanvas;
                const x = event.clientX * (pc.clientWidth / (pc.clientWidth * e.zoom)) - (pc.getBoundingClientRect().x * (pc.clientWidth / (pc.clientWidth * e.zoom)));
                const y = event.clientY * (pc.clientHeight / (pc.clientHeight * e.zoom)) - (pc.getBoundingClientRect().y * (pc.clientHeight / (pc.clientHeight * e.zoom)));
                this.addNode(typeKey, x - 110, y - 30);
            },

            addAtCenter(typeKey) {
                const e = editor, rect = e.container.getBoundingClientRect();
                const x = (rect.width / 2 - e.canvas_x) / e.zoom - 110 + (Math.random() * 40 - 20);
                const y = (rect.height / 2 - e.canvas_y) / e.zoom - 30 + (Math.random() * 40 - 20);
                const id = this.addNode(typeKey, x, y);
                if (id) this.select(id);
            },

            select(id) {
                const node = editor.getNodeFromId(id);
                this.selected = id;
                this.config = JSON.parse(JSON.stringify(node.data.config || {}));
            },

            selectedType() {
                return this.selected ? (TYPES[editor.getNodeFromId(this.selected).data.type] || { fields: [] }) : { fields: [] };
            },

            selectedNodeId() {
                return this.selected ? editor.getNodeFromId(this.selected).data.nodeId : null;
            },

            setField(key, value) {
                this.config[key] = value;
                this.commit();
            },

            toggleMulti(key, value, on) {
                const list = (this.config[key] || []).map(String).filter(v => v !== String(value));
                if (on) list.push(String(value));
                this.config[key] = list;
                this.commit();
            },

            commit() {
                const node = editor.getNodeFromId(this.selected);
                const data = Object.assign({}, node.data, { config: JSON.parse(JSON.stringify(this.config)) });
                editor.updateNodeDataFromId(this.selected, data);
                const content = document.querySelector('#node-' + this.selected + ' .drawflow_content_node');
                if (content) content.innerHTML = this.nodeHtml(data.type, data.config);

                // Alte Fehlermeldungen gelten nach einer Änderung nicht mehr; der nächste Speicherversuch prüft neu.
                if (this.nodeErrors[data.nodeId]) {
                    const { [data.nodeId]: _, ...rest } = this.nodeErrors;
                    this.nodeErrors = rest;
                    document.getElementById('node-' + this.selected)?.classList.remove('wf-has-error');
                }
            },

            removeSelected() {
                if (this.selected) { editor.removeNodeId('node-' + this.selected); this.selected = null; }
            },

            graph() {
                const data = editor.export().drawflow.Home.data;
                const nodes = [], edges = [];
                Object.values(data).forEach(n => {
                    nodes.push({ id: n.data.nodeId, type: n.data.type, config: n.data.config || {}, position: { x: Math.round(n.pos_x), y: Math.round(n.pos_y) } });
                    const outputKeys = Object.keys((TYPES[n.data.type] || {}).outputs || {});
                    Object.entries(n.outputs || {}).forEach(([cls, out]) => {
                        const key = outputKeys[parseInt(cls.replace('output_', ''), 10) - 1];
                        (out.connections || []).forEach(c => edges.push({ from: n.data.nodeId, output: key, to: data[c.node].data.nodeId }));
                    });
                });
                return { nodes, edges };
            },

            markNodes(className, nodeIds) {
                const data = editor.export().drawflow.Home.data;
                Object.values(data).forEach(n => {
                    const el = document.getElementById('node-' + n.id);
                    if (el) el.classList.toggle(className, nodeIds.includes(n.data.nodeId));
                });
            },

            async request(url, method, body) {
                const res = await fetch(url, {
                    method, credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify(body),
                });
                return { status: res.status, data: await res.json().catch(() => ({})) };
            },

            async save() {
                this.saving = true;
                this.message = '';
                const { status, data } = await this.request('{{ route('admin.workflows.update', $workflow) }}', 'PUT', {
                    name: this.name, description: this.description, is_active: this.isActive, graph: this.graph(),
                });
                this.saving = false;

                if (status === 200 && data.ok) {
                    this.generalErrors = []; this.nodeErrors = {};
                    this.markNodes('wf-has-error', []);
                    this.messageType = 'success';
                    this.message = 'Gespeichert (Version ' + data.version + '). Der Ablauf gilt ab sofort.';
                    return;
                }

                this.messageType = 'error';
                this.generalErrors = data.errors?.general || Object.values(data.errors || {}).flat().filter(e => typeof e === 'string');
                this.nodeErrors = data.errors?.nodes || {};
                this.markNodes('wf-has-error', Object.keys(this.nodeErrors));
                this.message = 'Nicht gespeichert. Bitte die markierten Bausteine prüfen.';
            },

            async simulate() {
                this.simulating = true;
                const { data } = await this.request('{{ route('admin.workflows.simulate', $workflow) }}', 'POST', Object.assign({ graph: this.graph() }, {
                    username: this.sim.username,
                    application_id: this.sim.application_id || null,
                    method: this.sim.method,
                    event: @json($workflow->phase === 'event') ? this.sim.event : null,
                    ip: this.sim.ip || null,
                    time: this.sim.time || null,
                }));
                this.simulating = false;
                this.simResult = data.ok ? data : { outcome: 'error', message: data.message || Object.values(data.errors || {}).flat().join(' '), steps: [] };
                this.markNodes('wf-in-path', (this.simResult.steps || []).map(s => s.node));
            },

            outputLabel(step) {
                const node = Object.values(editor.export().drawflow.Home.data).find(n => n.data.nodeId === step.node);
                return node ? ((TYPES[node.data.type] || {}).outputs || {})[step.output] : step.output;
            },
        };
    }
</script>
@endpush
