#!/usr/bin/env sh
set -eu

cookie="${RABBITMQ_ERLANG_COOKIE:?RABBITMQ_ERLANG_COOKIE is required}"

for node in rabbit@rabbitmq-replica-1 rabbit@rabbitmq-replica-2; do
  rabbitmqctl --erlang-cookie "$cookie" --node "$node" stop_app
  rabbitmqctl --erlang-cookie "$cookie" --node "$node" reset
  rabbitmqctl --erlang-cookie "$cookie" --node "$node" join_cluster rabbit@rabbitmq-primary
  rabbitmqctl --erlang-cookie "$cookie" --node "$node" start_app
done

rabbitmqctl --erlang-cookie "$cookie" --node rabbit@rabbitmq-primary await_online_nodes 3
