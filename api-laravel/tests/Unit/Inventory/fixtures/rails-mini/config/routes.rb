Rails.application.routes.draw do
  post "/graphql", to: "graphql#execute"

  get "/health", to: "application#health"

  namespace :api do
    namespace :v1 do
      draw(:shared_api)
    end
  end
end
