# Introduction

In ZEUS, Process is defined as an instance of a PHP application that is being executed. 

The execution of a single process must progress in a sequential fashion, therefore to achieve true concurrency, ZEUS may use _Process Scheduler_ to instantiate more than one such process at the same time.

# Process states

When a process executes, it passes through different states. In ZEUS, a process can have one of the following five states at a time:

![Process life-cycle figure](http://php.webtutor.pl/zeus/zeus-process-lifecycle.png)

Process can use hardware resources in only two states: waiting and running.

The following table describes each state in details:

| State        | Description                                                                                                                                 |
|--------------|---------------------------------------------------------------------------------------------------------------------------------------------|
| `STARTED`    | An initial state when a process is first created                                                                                            |
| `WAITING`    | Process is in a waiting state if it needs to wait for a resource, such as waiting for network connection, or some data to become available  |
| `RUNNING`    | Process sets the state to running just before it starts processing its data                                                                 |
| `TERMINATED` | When process is terminated by the Process Scheduler, it is moved to the terminated state where it waits to be removed from the Task Pool    |
| `EXITED`     | This state is set when process finishes its execution, and it waits to be removed from the Task Pool                                        |
