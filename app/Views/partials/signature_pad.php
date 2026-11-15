<?php defined('NX_ROOT') || exit; ?>
<div x-data="signaturePad()" class="space-y-2">
  <div class="flex items-center justify-between">
    <span class="text-sm font-medium text-zinc-700">Signature</span>
    <div class="seg text-xs"><button type="button" :class="mode === 'draw' && 'on'" @click="mode = 'draw'">Draw</button><button type="button" :class="mode === 'type' && 'on'" @click="mode = 'type'">Type</button></div>
  </div>
  <input x-show="mode === 'type'" x-cloak type="text" class="input bg-white text-zinc-900" x-model="typed" placeholder="Type your name" aria-label="Type your signature">
  <div class="relative rounded-lg border-2 border-dashed border-zinc-300 bg-white">
    <canvas x-ref="canvas" class="block h-40 w-full touch-none" role="img" aria-label="Signature area"></canvas>
    <span x-show="!drawn && mode === 'draw'" class="pointer-events-none absolute inset-0 grid place-items-center text-sm text-zinc-400">Sign here with your mouse, finger or stylus</span>
    <button type="button" x-show="drawn" x-cloak class="absolute right-2 top-2 rounded-md bg-white/90 px-2 py-1 text-xs text-zinc-600 shadow ring-1 ring-zinc-200 hover:text-red-600" @click="clear()">Clear</button>
  </div>
  <input type="hidden" name="signature" :value="value">
</div>
