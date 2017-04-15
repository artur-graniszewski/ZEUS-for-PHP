# Introduction

ZEUS comes equipped with the Inter Process Communication Service which enables multiple processes to communicate with one another and share their data.

# IPC adapters

The _IPC Server_ is shipped with four different IPC implementations (FIFO, APCu Shared Memory, SystemV, Socket) that can be enabled in ZEUS configuration depending on the operating system in use, or a developer preference.

By default, ZEUS is configured to use Named Pipes (FIFO) mechanism to communicate with its processes. 

_If selected IPC implementation is not yet supported or is ineffective on a given platform, plugging a different IPC adapter or writing a new one may be necessary._