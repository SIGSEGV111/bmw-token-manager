#!/bin/bash -eu
source "/opt/amp-bash-commons/shell-util.sh"

ensureProgramsInstalled jq openssl curl
parseCommandlineArguments "c:client-id:BMW_CLIENT_ID=string" "v:vin:BMW_VIN=string" "t:token-file=string?token.json" -- "$@"

function getJsonValue()
{
	jq -r "$1" <<<"$json"
}

echo "generating challenge ..."
code_verifier="$(openssl rand -base64 96 | tr -d '=+/ ' | cut -c1-96)"
code_challenge="$(printf '%s' "$code_verifier" \
	| openssl dgst -sha256 -binary \
	| openssl base64 -A \
	| tr '+/' '-_' \
	| tr -d '=')"

echo "requesting device_code ..."
json="$(curl -sS \
	-H 'Accept: application/json' \
	-H 'Content-Type: application/x-www-form-urlencoded' \
	--data-urlencode "client_id=$__client_id" \
	--data-urlencode "scope=authenticate_user openid cardata:streaming:read" \
	--data-urlencode "code_challenge=$code_challenge" \
	--data-urlencode "code_challenge_method=S256" \
	'https://customer.bmwgroup.com/gcdm/oauth/device/code' \
	| jq)"

jq <<<"$json"

device_code=$(getJsonValue ".device_code")
verification_uri_complete=$(getJsonValue ".verification_uri_complete")

echo "authorizing device_code ..."
silent firefox "$verification_uri_complete" &
sleep 20

echo "fetching initial token ..."
json="$(curl -sS \
	-H 'Accept: application/json' \
	-H 'Content-Type: application/x-www-form-urlencoded' \
	--data-urlencode 'grant_type=urn:ietf:params:oauth:grant-type:device_code' \
	--data-urlencode "device_code=$device_code" \
	--data-urlencode "client_id=$__client_id" \
	--data-urlencode "code_verifier=$code_verifier" \
	'https://customer.bmwgroup.com/gcdm/oauth/token' \
	| jq)"

jq <<<"$json"

echo "adding metadata ..."
json="$(jq --arg client_id "$__client_id" --arg vin "$__vin" '.client_id = $client_id | .vin = $vin' <<< "$json")"

echo "writing token file ..."
echo "$json" > "$__token_file"

echo "ALL DONE"
