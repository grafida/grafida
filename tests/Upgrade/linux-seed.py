#!/usr/bin/env python3
# Grafida — Joomla content editing, untethered.
#
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later
#
# Builds the Linux seed for the upgrade test (gh-92): 0.3's own PHP running seed.phar.
#
#   linux-seed.py GRAFIDA_03_BINARY SEED_PHAR OUT
#
# Boson's 0.3 Linux build is phpmicro's stock *combined* runtime, not the sibling-phar stub macOS and
# Windows used: one ELF (`grafida/grafida` in the 0.3 tarball, ~44 MB), then an INI block (magic
# FD F6 69 E6, a big-endian length, `ffi.enable=1` & co.), then 0.3's own PHAR. A copy of it renamed
# `seed` would run 0.3 itself, so OUT is that ELF and that INI block with seed.phar in place of 0.3's
# PHAR: the same PHP, with the same settings. The ELF ends where its section headers end.

import struct
import sys

if len(sys.argv) != 4:
    sys.exit('usage: linux-seed.py GRAFIDA_03_BINARY SEED_PHAR OUT')

binary = open(sys.argv[1], 'rb').read()
if binary[:4] != b'\x7fELF':
    sys.exit('%s is not an ELF file' % sys.argv[1])

shoff = struct.unpack_from('<Q', binary, 0x28)[0]
entsize, count = struct.unpack_from('<HH', binary, 0x3A)
end = shoff + entsize * count
ini = 0
if binary[end:end + 4] == b'\xfd\xf6\x69\xe6':
    ini = 8 + struct.unpack_from('>I', binary, end + 4)[0]

with open(sys.argv[3], 'wb') as out:
    out.write(binary[:end + ini])
    out.write(open(sys.argv[2], 'rb').read())

print('%s: the %d-byte ELF, a %d-byte INI block %r, then %s' % (sys.argv[3], end, ini, binary[end + 8:end + ini], sys.argv[2]))
