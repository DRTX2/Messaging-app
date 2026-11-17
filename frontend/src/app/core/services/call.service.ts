import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../../environments/environment';
import { generateUUID } from '../../shared/utils/uuid';
import { WebSocketService } from './websocket.service';

export type CallMedia = 'audio' | 'video';
export type CallPhase = 'idle' | 'outgoing' | 'incoming' | 'connecting' | 'active';
type SignalType = 'offer' | 'answer' | 'candidate' | 'reject' | 'busy' | 'hangup';

export interface CallSignalEvent {
  call_id: string;
  conversation_id: number;
  type: SignalType;
  media: CallMedia;
  from: { id: number; name: string };
  data: (RTCSessionDescriptionInit & RTCIceCandidateInit) | null;
}

interface CallState {
  phase: CallPhase;
  callId: string | null;
  conversationId: number | null;
  peerName: string;
  media: CallMedia;
}

const IDLE: CallState = { phase: 'idle', callId: null, conversationId: null, peerName: '', media: 'audio' };
const RING_TIMEOUT_MS = 30_000;

/**
 * 1:1 audio/video calls over WebRTC. The API only relays signalling (offer/answer/ICE) through the
 * callee's private Reverb channel; audio and video flow peer to peer.
 * shortcut: STUN only. Peers behind symmetric NAT need a TURN server (add it to environment.iceServers).
 */
@Injectable({ providedIn: 'root' })
export class CallService {
  private http = inject(HttpClient);
  private ws = inject(WebSocketService);

  readonly state = signal<CallState>(IDLE);
  readonly localStream = signal<MediaStream | null>(null);
  readonly remoteStream = signal<MediaStream | null>(null);
  readonly muted = signal(false);
  readonly cameraOff = signal(false);
  readonly notice = signal<string | null>(null);
  readonly inCall = computed(() => this.state().phase !== 'idle');

  private pc: RTCPeerConnection | null = null;
  private pendingOffer: RTCSessionDescriptionInit | null = null;
  private pendingCandidates: RTCIceCandidateInit[] = [];
  private ringTimer: ReturnType<typeof setTimeout> | null = null;
  private sendQueue: Promise<unknown> = Promise.resolve();

  /** Start listening for incoming calls on the user's own channel. */
  init(userId: number): void {
    this.ws.listenToCalls(userId, (e) => void this.onSignal(e));
  }

  async start(conversationId: number, peerName: string, media: CallMedia): Promise<void> {
    if (this.inCall()) return;
    this.notice.set(null);
    this.state.set({ phase: 'outgoing', callId: generateUUID(), conversationId, peerName, media });
    try {
      const pc = await this.openPeer(media);
      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);
      await this.send('offer', { type: offer.type, sdp: offer.sdp });
      this.ringTimer = setTimeout(() => void this.hangup('No answer'), RING_TIMEOUT_MS);
    } catch (err) {
      this.end(this.describe(err));
    }
  }

  async accept(): Promise<void> {
    if (this.state().phase !== 'incoming' || !this.pendingOffer) return;
    this.state.update((s) => ({ ...s, phase: 'connecting' }));
    try {
      const pc = await this.openPeer(this.state().media);
      await pc.setRemoteDescription(this.pendingOffer);
      await this.flushCandidates();
      const answer = await pc.createAnswer();
      await pc.setLocalDescription(answer);
      await this.send('answer', { type: answer.type, sdp: answer.sdp });
    } catch (err) {
      await this.send('reject').catch(() => {});
      this.end(this.describe(err));
    }
  }

  async reject(): Promise<void> {
    if (this.state().phase !== 'incoming') return;
    await this.send('reject').catch(() => {});
    this.end();
  }

  async hangup(notice?: string): Promise<void> {
    if (!this.inCall()) return;
    await this.send('hangup').catch(() => {});
    this.end(notice);
  }

  toggleMute(): void {
    this.muted.update((m) => !m);
    this.localStream()?.getAudioTracks().forEach((t) => (t.enabled = !this.muted()));
  }

  toggleCamera(): void {
    this.cameraOff.update((c) => !c);
    this.localStream()?.getVideoTracks().forEach((t) => (t.enabled = !this.cameraOff()));
  }

  // ------------------------------------------------------------------ signalling in

  private async onSignal(e: CallSignalEvent): Promise<void> {
    const s = this.state();

    if (e.type === 'offer') {
      if (s.phase !== 'idle') {
        // Already on a call: tell the caller instead of ringing over it.
        await this.post(e.conversation_id, { call_id: e.call_id, type: 'busy', media: e.media }).catch(() => {});
        return;
      }
      this.pendingOffer = e.data as RTCSessionDescriptionInit;
      this.pendingCandidates = [];
      this.notice.set(null);
      this.state.set({ phase: 'incoming', callId: e.call_id, conversationId: e.conversation_id, peerName: e.from.name, media: e.media });
      this.ringTimer = setTimeout(() => this.end('Missed call'), RING_TIMEOUT_MS);
      return;
    }

    if (e.call_id !== s.callId) return; // stale signal from a call that already ended

    switch (e.type) {
      case 'answer':
        if (!this.pc) return;
        await this.pc.setRemoteDescription(e.data as RTCSessionDescriptionInit);
        await this.flushCandidates();
        this.clearRing();
        this.state.update((st) => ({ ...st, phase: 'connecting' }));
        break;
      case 'candidate':
        if (!e.data) return;
        // Candidates can arrive before the remote description is set (trickle ICE): queue them.
        if (this.pc?.remoteDescription) await this.pc.addIceCandidate(e.data).catch(() => {});
        else this.pendingCandidates.push(e.data);
        break;
      case 'reject':
        this.end(`${s.peerName} declined the call`);
        break;
      case 'busy':
        this.end(`${s.peerName} is on another call`);
        break;
      case 'hangup':
        this.end('Call ended');
        break;
    }
  }

  // ------------------------------------------------------------------ internals

  private async openPeer(media: CallMedia): Promise<RTCPeerConnection> {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: media === 'video' });
    this.localStream.set(stream);

    const iceServers = (environment as { iceServers?: RTCIceServer[] }).iceServers ?? [{ urls: 'stun:stun.l.google.com:19302' }];
    const pc = new RTCPeerConnection({ iceServers });
    this.pc = pc;
    stream.getTracks().forEach((t) => pc.addTrack(t, stream));

    pc.onicecandidate = (ev) => {
      if (ev.candidate) void this.send('candidate', ev.candidate.toJSON()).catch(() => {});
    };
    pc.ontrack = (ev) => this.remoteStream.set(ev.streams[0] ?? new MediaStream([ev.track]));
    pc.onconnectionstatechange = () => {
      if (pc !== this.pc) return;
      if (pc.connectionState === 'connected') {
        this.clearRing();
        this.state.update((st) => ({ ...st, phase: 'active' }));
      } else if (pc.connectionState === 'failed') {
        void this.hangup('Connection lost');
      }
    };
    return pc;
  }

  private async flushCandidates(): Promise<void> {
    const queued = this.pendingCandidates.splice(0);
    for (const c of queued) await this.pc?.addIceCandidate(c).catch(() => {});
  }

  private send(type: SignalType, data?: object): Promise<unknown> {
    const s = this.state();
    if (!s.callId || s.conversationId === null) return Promise.resolve();
    const body = { call_id: s.callId, type, media: s.media, ...(data ? { data } : {}) };
    // One request at a time: ICE candidates are gathered before the offer's POST returns and must not overtake it.
    const sent = this.sendQueue.then(() => this.post(s.conversationId!, body));
    this.sendQueue = sent.catch(() => {});
    return sent;
  }

  private post(conversationId: number, body: object): Promise<unknown> {
    return firstValueFrom(this.http.post(`${environment.apiUrl}/conversations/${conversationId}/calls/signal`, body));
  }

  private clearRing(): void {
    if (this.ringTimer) clearTimeout(this.ringTimer);
    this.ringTimer = null;
  }

  /** Release camera/microphone and reset. Always safe to call. */
  private end(notice?: string): void {
    this.clearRing();
    this.localStream()?.getTracks().forEach((t) => t.stop());
    this.pc?.close();
    this.pc = null;
    this.pendingOffer = null;
    this.pendingCandidates = [];
    this.localStream.set(null);
    this.remoteStream.set(null);
    this.muted.set(false);
    this.cameraOff.set(false);
    this.state.set(IDLE);
    if (notice) {
      this.notice.set(notice);
      setTimeout(() => this.notice() === notice && this.notice.set(null), 4000);
    }
  }

  private describe(err: unknown): string {
    const name = (err as DOMException)?.name;
    if (name === 'NotAllowedError') return 'Microphone or camera permission was denied';
    if (name === 'NotFoundError') return 'No microphone or camera found';
    return 'The call could not be started';
  }
}
