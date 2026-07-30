import {
  createQueryString,
  requestJson,
} from "@/lib/http";
import type {
  Contract,
  ContractFormPayload,
  ContractLifecycleAction,
  ContractResponse,
  ContractsResponse,
  ContractUpdatePayload,
} from "@/types/contract";

export type ContractsQuery = {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
  reservation_id?: string;
};

export async function fetchContracts(
  token: string,
  params: ContractsQuery = {}
): Promise<ContractsResponse> {
  const query = createQueryString(params);
  const path = query ? `/contracts?${query}` : "/contracts";

  return requestJson<ContractsResponse>(path, { token });
}

export async function fetchContract(
  token: string,
  contractId: string
): Promise<Contract> {
  const result = await requestJson<ContractResponse>(
    `/contracts/${contractId}`,
    { token }
  );

  return result.data.contract;
}

export async function createContract(
  token: string,
  payload: ContractFormPayload
): Promise<Contract> {
  const result = await requestJson<ContractResponse>("/contracts", {
    token,
    method: "POST",
    body: payload,
  });

  return result.data.contract;
}

export async function updateContract(
  token: string,
  contractId: string,
  payload: ContractUpdatePayload
): Promise<Contract> {
  const result = await requestJson<ContractResponse>(
    `/contracts/${contractId}`,
    {
      token,
      method: "PATCH",
      body: payload,
    }
  );

  return result.data.contract;
}

export async function executeContractLifecycleAction(
  token: string,
  contractId: string,
  action: ContractLifecycleAction
): Promise<Contract> {
  const result = await requestJson<ContractResponse>(
    `/contracts/${contractId}/${action}`,
    {
      token,
      method: "PATCH",
    }
  );

  return result.data.contract;
}
