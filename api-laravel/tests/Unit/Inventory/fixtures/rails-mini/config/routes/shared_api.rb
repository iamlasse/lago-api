resources :customers, param: :external_id, only: %i[create index show destroy] do
  get :portal_url

  scope module: :customers do
    resources :invoices, only: %i[index]
  end
end

get "/organizations", to: "organizations#show"

resources :webhooks, only: [] do
  post "stripe/:organization_id", to: "webhooks#stripe", on: :collection, as: :stripe
end
