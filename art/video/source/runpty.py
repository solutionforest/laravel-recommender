import os, pty, sys, fcntl, termios, struct, select
cmd = sys.argv[1:]
pid, fd = pty.fork()
if pid == 0:
    os.environ['TERM'] = 'xterm-256color'; os.environ['COLUMNS'] = '100'; os.environ['LINES'] = '40'
    os.execvp(cmd[0], cmd)
fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack('HHHH', 40, 100, 0, 0))
out = b''
while True:
    try:
        r, _, _ = select.select([fd], [], [], 60)
        if not r: break
        data = os.read(fd, 65536)
        if not data: break
        out += data
    except OSError:
        break
os.waitpid(pid, 0)
sys.stdout.buffer.write(out)
