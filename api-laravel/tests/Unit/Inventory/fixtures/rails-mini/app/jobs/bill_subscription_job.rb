class BillSubscriptionJob < ApplicationJob
  queue_as do
    SidekiqQueueNames.billing
  end

  unique :until_executed, on_conflict: :log, lock_ttl: 12.hours

  retry_on Sequenced::SequenceError, wait: :polynomially_longer, attempts: 10
end
