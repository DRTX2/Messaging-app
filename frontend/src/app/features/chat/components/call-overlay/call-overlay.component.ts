import { ChangeDetectionStrategy, Component, ElementRef, effect, inject, viewChild } from '@angular/core';
import { CallService } from '../../../../core/services/call.service';

/** Incoming-call prompt, in-call screen and short notices ("declined", "missed"). Rendered once, app-wide. */
@Component({
  selector: 'app-call-overlay',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (call.notice(); as notice) {
      <div role="status" class="fixed top-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-full bg-zinc-900 text-white text-sm shadow-lg">{{ notice }}</div>
    }

    @if (call.inCall()) {
      <div role="dialog" aria-modal="true" [attr.aria-label]="label()" class="fixed inset-0 z-50 bg-zinc-950 text-white flex flex-col">
        <div class="relative flex-1 flex items-center justify-center overflow-hidden">
          <video #remote autoplay playsinline class="absolute inset-0 w-full h-full object-cover"
            [class.hidden]="call.state().media !== 'video' || call.state().phase !== 'active'"></video>

          @if (call.state().media !== 'video' || call.state().phase !== 'active') {
            <div class="relative text-center px-6">
              <div class="mx-auto mb-5 w-24 h-24 rounded-full bg-indigo-600 grid place-items-center text-3xl font-semibold"
                [class.animate-pulse]="call.state().phase !== 'active'">{{ call.state().peerName.charAt(0).toUpperCase() }}</div>
              <h2 class="text-2xl font-semibold">{{ call.state().peerName }}</h2>
              <p class="mt-1 text-zinc-400" aria-live="polite">{{ label() }}</p>
            </div>
          }

          <video #local autoplay playsinline muted
            class="absolute bottom-4 right-4 w-32 md:w-48 aspect-video rounded-xl object-cover border border-white/20 bg-zinc-900"
            [class.hidden]="call.state().media !== 'video' || !call.localStream()"></video>
        </div>

        <div class="flex items-center justify-center gap-4 p-6 bg-zinc-900/80">
          @if (call.state().phase === 'incoming') {
            <button (click)="call.reject()" class="px-6 h-12 rounded-full bg-red-600 hover:bg-red-500 font-medium">Decline</button>
            <button (click)="call.accept()" class="px-6 h-12 rounded-full bg-emerald-600 hover:bg-emerald-500 font-medium">Accept</button>
          } @else {
            <button (click)="call.toggleMute()" [attr.aria-pressed]="call.muted()"
              class="px-5 h-12 rounded-full font-medium" [class]="call.muted() ? 'bg-white text-zinc-900' : 'bg-zinc-700 hover:bg-zinc-600'">
              {{ call.muted() ? 'Unmute' : 'Mute' }}
            </button>
            @if (call.state().media === 'video') {
              <button (click)="call.toggleCamera()" [attr.aria-pressed]="call.cameraOff()"
                class="px-5 h-12 rounded-full font-medium" [class]="call.cameraOff() ? 'bg-white text-zinc-900' : 'bg-zinc-700 hover:bg-zinc-600'">
                {{ call.cameraOff() ? 'Camera on' : 'Camera off' }}
              </button>
            }
            <button (click)="call.hangup()" class="px-6 h-12 rounded-full bg-red-600 hover:bg-red-500 font-medium">Hang up</button>
          }
        </div>
      </div>
    }
  `,
})
export class CallOverlayComponent {
  readonly call = inject(CallService);
  private remote = viewChild<ElementRef<HTMLVideoElement>>('remote');
  private local = viewChild<ElementRef<HTMLVideoElement>>('local');

  constructor() {
    // A <video> (not <audio>) plays the remote stream for voice calls too; it is just hidden.
    effect(() => { const el = this.remote()?.nativeElement; if (el) el.srcObject = this.call.remoteStream(); });
    effect(() => { const el = this.local()?.nativeElement; if (el) el.srcObject = this.call.localStream(); });
  }

  label(): string {
    const s = this.call.state();
    const kind = s.media === 'video' ? 'video call' : 'voice call';
    switch (s.phase) {
      case 'incoming': return `Incoming ${kind}`;
      case 'outgoing': return `Calling… (${kind})`;
      case 'connecting': return 'Connecting…';
      default: return `In ${kind}`;
    }
  }
}
