## Development environment

[Lando.dev](https://docs.lando.dev/) is used for local development, this means the project is running inside docker.
Lando provides many helper methods to make development easier and to interact with the docker containers.

### Running general command inside the container

- Any time you need to run a command inside the container, use `lando ssh -c "comand to run"` instead of running it on
  the host. Example, if you wanted to list files in the project root, you would run `lando ssh -c "ls"` instead of `ls`.
- The root of the codebase is mounted inside the container at `/app`.
- There are many helper commands for lando in the `.lando.yml` under `tooling` you should be familiar with these
  commands. You can also run `lando` without any arguments to see a list of commands.

### Running artisan commands

- Any time you need to run an artisan command, use `lando artisan` instead of running `artisan` directly on the host.
  Example, if you wanted to run a migration you would run `lando artisan migrate` instead of `artisan migrate`.

### Running composer commands

- Any time you need to run a composer command, use `lando composer` instead of running `composer` directly on the host.
  Example, if you wanted to install a dependency you would run `lando composer require package-name` instead of
  `composer require package-name`.

### Running npm commands

- Any time you need to run a npm command, use `lando npm` instead of running `npm` directly on the host. Example, if you
  wanted to install a dependency you would run `lando npm install package-name` instead of `npm install package-name`.
