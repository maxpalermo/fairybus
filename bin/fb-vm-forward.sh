#!/usr/bin/env bash
# fb-vm-forward.sh — port-forward host -> VM Boxes (rete slirp/user)
# Da rilanciare dopo ogni avvio della VM.
# Uso: ./fb-vm-forward.sh [nome-dominio]   (default: debian13)
DOM="${1:-debian13}"
export LIBVIRT_DEFAULT_URI=qemu:///session

if [ "$(virsh domstate "$DOM" 2>/dev/null)" != "running" ]; then
    echo "La VM '$DOM' non e' avviata." >&2
    exit 1
fi

virsh qemu-monitor-command "$DOM" --hmp 'hostfwd_add hostnet0 tcp::8890-:8080' >/dev/null
virsh qemu-monitor-command "$DOM" --hmp 'hostfwd_add hostnet0 tcp::2222-:22'   >/dev/null

echo "Forwarding attivi su '$DOM':"
echo "  http://localhost:8890  ->  app Fairy Bus (VM:8080)"
echo "  ssh -p 2222 <utente>@localhost  ->  SSH della VM"
