import http from "@/api/index.js";

export const dataAuthCreate = (data, headers = {}) => {
  return http.post("/admin/data_auth/create", data, headers);
};

export const dataAuthDelete = (data, headers = {}) => {
  return http.delete("/admin/data_auth/delete", data, headers);
};

export const dataAuthUpdate = (data, headers = {}) => {
  return http.put("/admin/data_auth/update", data, headers);
};

export const dataAuthQuery = (data, headers = {}) => {
  return http.get("/admin/data_auth/index", data, headers);
};

export const dataAuthFields = (data, headers = {}) => {
  return http.get("/admin/data_auth/fields", data, headers);
};
